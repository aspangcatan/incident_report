import { onBeforeUnmount, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';

const MAX_RETRY_MS = 30 * 1000;
// The layout remounts on every visit; keep the socket through that, close it once no page uses it.
const IDLE_CLOSE_MS = 3000;

// One connection per tab, shared by every page that mounts the layout.
const listeners = new Set();
let socket = null;
let socketUser = null;
let retryMs = 1000;
let retryTimer = null;
let closeTimer = null;
let currentConfig = () => null;

function socketUrl(config) {
    const base = config.url || `${window.location.protocol === 'https:' ? 'wss' : 'ws'}://${window.location.hostname}:${config.port}`;
    return `${base.replace(/\/$/, '')}/?token=${encodeURIComponent(config.token)}`;
}

const userOf = (config) => config?.token?.split('.')[0] ?? null;

function disconnect() {
    window.clearTimeout(retryTimer);
    if (socket) {
        socket.onclose = null;
        socket.close();
    }
    socket = null;
    socketUser = null;
}

function connect() {
    // Read the latest config each time: background refreshes issue fresh tokens.
    const config = currentConfig();
    if (!config?.token || listeners.size === 0) return;

    socketUser = userOf(config);
    socket = new WebSocket(socketUrl(config));
    socket.onopen = () => {
        retryMs = 1000;
    };
    socket.onmessage = (message) => {
        try {
            const { event, data } = JSON.parse(message.data);
            if (event !== 'connected') listeners.forEach((listener) => listener(event, data));
        } catch {
            // Ignore anything that is not our JSON frame.
        }
    };
    socket.onclose = () => {
        socket = null;
        retryTimer = window.setTimeout(connect, retryMs);
        retryMs = Math.min(retryMs * 2, MAX_RETRY_MS);
    };
}

/**
 * Calls onEvent(event, data) for each push from realtime/server.js while the
 * calling component is mounted. Does nothing when the server sent no
 * `realtime` config (real-time is off); pages then rely on the 30-second refresh.
 */
export function useRealtime(onEvent) {
    const page = usePage();

    onMounted(() => {
        currentConfig = () => page.props.realtime;
        listeners.add(onEvent);
        window.clearTimeout(closeTimer);

        // A different person signed in on this tab: never reuse the previous person's connection.
        if (socket && socketUser !== userOf(currentConfig())) disconnect();
        if (!socket) connect();
    });

    onBeforeUnmount(() => {
        listeners.delete(onEvent);
        if (listeners.size === 0) closeTimer = window.setTimeout(disconnect, IDLE_CLOSE_MS);
    });
}
