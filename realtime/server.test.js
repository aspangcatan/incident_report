// node --test realtime/   (npm run test:realtime)
import { createHmac } from 'node:crypto';
import { once } from 'node:events';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { WebSocket } from 'ws';
import { startServer, verifyToken } from './server.js';

const SECRET = 'test-secret';
const sign = (payload) => createHmac('sha256', SECRET).update(payload).digest('hex');
const tokenFor = (userId, expiresAt = Math.floor(Date.now() / 1000) + 600) => `${userId}.${expiresAt}.${sign(`${userId}.${expiresAt}`)}`;

async function withServer(run) {
    const server = startServer({ port: 0, host: '127.0.0.1', secret: SECRET });
    await once(server.http, 'listening');
    try {
        await run(server.http.address().port);
    } finally {
        await server.close();
    }
}

async function connect(port, token) {
    const ws = new WebSocket(`ws://127.0.0.1:${port}/?token=${token}`);
    const [frame] = await once(ws, 'message');
    assert.equal(JSON.parse(frame).event, 'connected');
    return ws;
}

function publish(port, payload, signature = sign(JSON.stringify(payload))) {
    return fetch(`http://127.0.0.1:${port}/publish`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Realtime-Signature': signature },
        body: JSON.stringify(payload),
    });
}

test('tokens: valid, forged and expired', () => {
    assert.equal(verifyToken(SECRET, tokenFor(5)), '5');
    assert.equal(verifyToken(SECRET, tokenFor(5).replace(/^5/, '6')), null);
    assert.equal(verifyToken(SECRET, tokenFor(5, 1000)), null);
    assert.equal(verifyToken(SECRET, 'garbage'), null);
});

test('a published event reaches only the addressed user', async () => {
    await withServer(async (port) => {
        const alice = await connect(port, tokenFor(1));
        const bob = await connect(port, tokenFor(2));
        let bobGotOne = false;
        bob.on('message', () => (bobGotOne = true));

        const received = once(alice, 'message');
        const response = await publish(port, { user_ids: [1], event: 'notification', data: { message: 'Hello' } });
        assert.deepEqual(await response.json(), { delivered: 1 });

        const [frame] = await received;
        assert.deepEqual(JSON.parse(frame), { event: 'notification', data: { message: 'Hello' } });
        await new Promise((r) => setTimeout(r, 50));
        assert.equal(bobGotOne, false);
        alice.close();
        bob.close();
    });
});

test('a publish with a bad signature is refused', async () => {
    await withServer(async (port) => {
        const response = await publish(port, { user_ids: [1], event: 'notification' }, 'forged');
        assert.equal(response.status, 401);
    });
});

test('a connection with a bad token is refused', async () => {
    await withServer(async (port) => {
        const ws = new WebSocket(`ws://127.0.0.1:${port}/?token=1.9999999999.forged`);
        const [error] = await once(ws, 'error');
        assert.match(error.message, /401/);
    });
});
