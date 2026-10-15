// Real-time notifications for the CSMC Incident/Accident Reporting System.
//
// Browsers connect over WebSocket with a short-lived token Laravel signs
// (App\Support\Realtime::tokenFor); Laravel pushes each new notification here
// with POST /publish, signed with the same secret. A person only ever receives
// events addressed to their own user id.
//
// Run: npm run realtime   (reads REALTIME_* from the project's .env)

import { createHmac, timingSafeEqual } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { WebSocketServer } from 'ws';

const MAX_BODY_BYTES = 64 * 1024;
const HEARTBEAT_MS = 30 * 1000;

/** REALTIME_* keys from the project's .env; real environment variables win. */
export function loadConfig(envPath = resolve(dirname(fileURLToPath(import.meta.url)), '../.env')) {
    const fromFile = {};
    try {
        for (const line of readFileSync(envPath, 'utf8').split(/\r?\n/)) {
            const match = line.match(/^\s*(REALTIME_[A-Z_]+)\s*=\s*(.*)\s*$/);
            if (match) fromFile[match[1]] = match[2].replace(/^(['"])(.*)\1$/, '$2');
        }
    } catch {
        // No .env: rely on the environment.
    }
    const value = (key, fallback) => process.env[key] ?? fromFile[key] ?? fallback;

    return {
        port: Number(value('REALTIME_PORT', 6001)),
        host: value('REALTIME_HOST', '0.0.0.0'),
        secret: value('REALTIME_SECRET', ''),
    };
}

const sign = (secret, payload) => createHmac('sha256', secret).update(payload).digest('hex');

function safeEqual(a, b) {
    const left = Buffer.from(a);
    const right = Buffer.from(b);
    return left.length === right.length && timingSafeEqual(left, right);
}

/** "<userId>.<expiresAtUnix>.<hmac>" → user id, or null when forged or expired. */
export function verifyToken(secret, token, now = Date.now()) {
    const [userId, expiresAt, signature] = String(token ?? '').split('.');
    if (!userId || !expiresAt || !signature) return null;
    if (!safeEqual(sign(secret, `${userId}.${expiresAt}`), signature)) return null;
    if (Number(expiresAt) * 1000 < now) return null;
    return userId;
}

export function startServer({ port, host, secret }) {
    if (!secret) throw new Error('REALTIME_SECRET is not set. Add it to .env (the same value Laravel uses).');

    const socketsByUser = new Map(); // userId -> Set<WebSocket>

    const http = createServer((request, response) => {
        const reply = (status, body) => {
            response.writeHead(status, { 'Content-Type': 'application/json' });
            response.end(JSON.stringify(body));
        };

        if (request.method === 'GET' && request.url === '/health') {
            return reply(200, { ok: true, users: socketsByUser.size });
        }
        if (request.method !== 'POST' || request.url !== '/publish') {
            return reply(404, { error: 'Not found' });
        }

        let body = '';
        request.on('data', (chunk) => {
            body += chunk;
            if (body.length > MAX_BODY_BYTES) request.destroy();
        });
        request.on('end', () => {
            if (!safeEqual(sign(secret, body), String(request.headers['x-realtime-signature'] ?? ''))) {
                return reply(401, { error: 'Bad signature' });
            }

            let message;
            try {
                message = JSON.parse(body);
            } catch {
                return reply(400, { error: 'Invalid JSON' });
            }

            const frame = JSON.stringify({ event: message.event, data: message.data ?? {} });
            let delivered = 0;
            for (const userId of message.user_ids ?? []) {
                for (const socket of socketsByUser.get(String(userId)) ?? []) {
                    if (socket.readyState === socket.OPEN) {
                        socket.send(frame);
                        delivered++;
                    }
                }
            }
            reply(200, { delivered });
        });
    });

    const wss = new WebSocketServer({ noServer: true });

    http.on('upgrade', (request, socket, head) => {
        const token = new URL(request.url, 'http://localhost').searchParams.get('token');
        const userId = verifyToken(secret, token);
        if (!userId) {
            socket.write('HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n');
            socket.destroy();
            return;
        }
        wss.handleUpgrade(request, socket, head, (ws) => {
            ws.userId = userId;
            wss.emit('connection', ws);
        });
    });

    wss.on('connection', (ws) => {
        if (!socketsByUser.has(ws.userId)) socketsByUser.set(ws.userId, new Set());
        socketsByUser.get(ws.userId).add(ws);
        ws.isAlive = true;
        ws.on('pong', () => {
            ws.isAlive = true;
        });
        ws.on('close', () => {
            const sockets = socketsByUser.get(ws.userId);
            sockets?.delete(ws);
            if (sockets?.size === 0) socketsByUser.delete(ws.userId);
        });
        ws.send(JSON.stringify({ event: 'connected', data: {} }));
    });

    // Drop connections that stopped answering (laptop asleep, network gone).
    const heartbeat = setInterval(() => {
        for (const ws of wss.clients) {
            if (!ws.isAlive) {
                ws.terminate();
                continue;
            }
            ws.isAlive = false;
            ws.ping();
        }
    }, HEARTBEAT_MS);

    http.listen(port, host);

    return {
        http,
        close: () => new Promise((done) => {
            clearInterval(heartbeat);
            for (const ws of wss.clients) ws.terminate();
            wss.close();
            http.close(() => done());
        }),
    };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const config = loadConfig();
    startServer(config);
    console.log(`Realtime server listening on ${config.host}:${config.port}`);
}
