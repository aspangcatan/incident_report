<?php

/*
|--------------------------------------------------------------------------
| Real-time notifications (realtime/server.js, a small Node "ws" server)
|--------------------------------------------------------------------------
| Laravel pushes each new notification to the Node server; browsers get it
| over a WebSocket. When disabled or the server is down, nothing breaks: the
| pages still refresh every 30 seconds (useAutoRefresh.js).
*/

return [
    'enabled' => (bool) env('REALTIME_ENABLED', false),

    // Shared with realtime/server.js. Signs browser tokens and Laravel's pushes.
    'secret' => env('REALTIME_SECRET'),

    // Where Laravel posts events (same machine as the Node server).
    'publish_url' => env('REALTIME_PUBLISH_URL', 'http://127.0.0.1:6001/publish'),

    // Where browsers connect. Leave empty to use ws(s)://<the page's host>:REALTIME_PORT.
    'public_url' => env('REALTIME_PUBLIC_URL'),
    'port' => (int) env('REALTIME_PORT', 6001),

    // Browser token lifetime; each page load and background refresh issues a new one.
    'token_ttl_minutes' => 720,
];
