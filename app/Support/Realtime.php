<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Talks to realtime/server.js: signs browser tokens and pushes events to users. */
final class Realtime
{
    private const DOWN_KEY = 'realtime:server-down';

    private const RETRY_AFTER_SECONDS = 60;

    public static function enabled(): bool
    {
        return (bool) config('realtime.enabled') && filled(config('realtime.secret'));
    }

    /** "<userId>.<expiresAtUnix>.<hmac>", verified by the Node server on connect. */
    public static function tokenFor(User $user): string
    {
        $payload = $user->id . '.' . now()->addMinutes(config('realtime.token_ttl_minutes'))->timestamp;

        return $payload . '.' . hash_hmac('sha256', $payload, config('realtime.secret'));
    }

    /** What the layout needs to connect, or null when real-time is off. */
    public static function clientConfig(User $user): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        return [
            'url' => config('realtime.public_url'),
            'port' => config('realtime.port'),
            'token' => self::tokenFor($user),
        ];
    }

    /**
     * Sends an event to these users' open browsers. Never throws: if the Node
     * server is down the event is dropped and pages catch up on their next refresh.
     *
     * @param  array<int|string>  $userIds
     */
    public static function push(array $userIds, string $event, array $data = []): void
    {
        // After a failure, skip pushes for a minute: a stopped server must not add a
        // connect timeout to every notification of every request.
        if (! self::enabled() || $userIds === [] || Cache::has(self::DOWN_KEY)) {
            return;
        }

        $body = json_encode(['user_ids' => array_values($userIds), 'event' => $event, 'data' => $data]);

        try {
            Http::withOptions(['connect_timeout' => 1, 'timeout' => 2])
                ->withHeaders(['X-Realtime-Signature' => hash_hmac('sha256', $body, config('realtime.secret'))])
                ->withBody($body, 'application/json')
                ->post(config('realtime.publish_url'));
        } catch (Throwable $e) {
            Cache::put(self::DOWN_KEY, true, now()->addSeconds(self::RETRY_AFTER_SECONDS));
            Log::warning('Realtime push failed; pausing pushes for ' . self::RETRY_AFTER_SECONDS . 's: ' . $e->getMessage());
        }
    }
}
