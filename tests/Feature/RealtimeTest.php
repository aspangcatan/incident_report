<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EffectivenessCheckDueNotification;
use App\Support\Realtime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Laravel's side of realtime/server.js: tokens for browsers, signed pushes for each new notification. */
class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    private function enable(): void
    {
        config([
            'realtime.enabled' => true,
            'realtime.secret' => 'test-secret',
            'realtime.publish_url' => 'http://127.0.0.1:6001/publish',
        ]);
    }

    /** A database notification, as any listener in the app would create one. */
    private function notify(User $user): void
    {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => EffectivenessCheckDueNotification::class,
            'data' => ['incident_id' => 7, 'message' => 'Effectiveness check due for IR-2026-000007.'],
        ]);
    }

    public function test_a_new_notification_is_pushed_to_its_owner_with_a_valid_signature(): void
    {
        $this->enable();
        Http::fake();
        $user = User::factory()->create();

        $this->notify($user);

        Http::assertSent(function (Request $request) use ($user) {
            $body = json_decode($request->body(), true);

            return $request->url() === 'http://127.0.0.1:6001/publish'
                && $request->header('X-Realtime-Signature')[0] === hash_hmac('sha256', $request->body(), 'test-secret')
                && $body['user_ids'] === [$user->id]
                && $body['event'] === 'notification'
                && $body['data']['type'] === 'EffectivenessCheckDueNotification'
                && $body['data']['data']['message'] === 'Effectiveness check due for IR-2026-000007.';
        });
    }

    public function test_nothing_is_pushed_while_real_time_is_off(): void
    {
        Http::fake();
        $this->notify(User::factory()->create());

        Http::assertNothingSent();
    }

    public function test_a_down_server_never_breaks_the_request(): void
    {
        $this->enable();
        // Nothing listens on port 1: a real "connection refused", as when the Node server is stopped.
        config(['realtime.publish_url' => 'http://127.0.0.1:1/publish']);
        $user = User::factory()->create();

        $this->notify($user);
        $this->assertSame(1, $user->notifications()->count());

        // Paused after the failure: the next notifications don't wait on the dead server.
        Http::fake();
        $this->notify($user);
        Http::assertNothingSent();

        $this->travel(61)->seconds();
        $this->notify($user);
        Http::assertSentCount(1);
    }

    public function test_signed_in_pages_get_a_token_the_node_server_can_verify(): void
    {
        $this->enable();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page
            ->where('realtime.port', 6001)
            ->where('realtime.token', function (string $token) use ($user) {
                [$id, $expiresAt, $signature] = explode('.', $token);

                return (int) $id === $user->id
                    && $expiresAt > now()->timestamp
                    && hash_equals(hash_hmac('sha256', "{$id}.{$expiresAt}", 'test-secret'), $signature);
            }));
    }

    public function test_no_token_is_issued_while_real_time_is_off(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn ($page) => $page->where('realtime', null));
        $this->assertFalse(Realtime::enabled());
    }
}
