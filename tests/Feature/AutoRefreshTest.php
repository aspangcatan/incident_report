<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The 30-second background refresh (useAutoRefresh.js) is an Inertia partial reload. */
class AutoRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function partialReload(string $url, string $component, array $only)
    {
        return $this->get($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => implode(',', $only),
        ]);
    }

    public function test_a_background_refresh_returns_only_the_badges_and_the_pages_list(): void
    {
        $this->actingAs(User::factory()->create());

        $props = $this->partialReload('/incidents?scope=my-reports', 'Incidents/Index', [
            'unreadNotificationsCount', 'queueCounts', 'pendingSafetyAlert', 'incidents',
        ])->assertOk()->json('props');

        $this->assertEqualsCanonicalizing(
            ['unreadNotificationsCount', 'queueCounts', 'pendingSafetyAlert', 'incidents', 'errors'],
            array_keys($props),
        );
    }

    public function test_a_logged_out_refresh_is_sent_to_the_login_page(): void
    {
        $this->partialReload('/incidents', 'Incidents/Index', ['queueCounts'])->assertRedirect('/login');
    }
}
