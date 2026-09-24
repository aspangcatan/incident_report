<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Auth/Login'));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_users_can_authenticate_with_their_tdh_username(): void
    {
        $user = User::factory()->create(['username' => 'apangcatan']);

        $response = $this->post('/login', [
            'username' => 'apangcatan',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/');
    }

    public function test_users_cannot_authenticate_with_invalid_password(): void
    {
        User::factory()->create(['username' => 'apangcatan']);

        $this->post('/login', [
            'username' => 'apangcatan',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_inactive_tdh_users_cannot_authenticate(): void
    {
        User::factory()->inactive()->create(['username' => 'retired']);

        $this->post('/login', [
            'username' => 'retired',
            'password' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_login_and_logout_never_touch_the_shared_remember_token(): void
    {
        $user = User::factory()->create(['username' => 'apangcatan', 'remember_token' => 'owned-by-hris']);

        $this->post('/login', ['username' => 'apangcatan', 'password' => 'password', 'remember' => true]);
        $this->post('/logout');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'remember_token' => 'owned-by-hris'], config('tdh.connection'));
    }

    public function test_login_never_asks_the_guard_to_remember_even_if_the_client_requests_it(): void
    {
        Event::fake([Login::class]);

        User::factory()->create(['username' => 'apangcatan']);

        $this->post('/login', [
            'username' => 'apangcatan',
            'password' => 'password',
            'remember' => true,
        ]);

        Event::assertDispatched(Login::class, fn ($event) => $event->remember === false);
    }

    public function test_login_succeeds_even_when_tdh_writes_are_blocked_in_production(): void
    {
        User::factory()->create(['username' => 'apangcatan']);

        // Faking the environment also flips Application::runningUnitTests(),
        // which the framework's VerifyCsrfToken middleware relies on to skip
        // CSRF checks in tests; disable it explicitly so this test isolates
        // the one thing it means to exercise (the read-only write guard).
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->app['env'] = 'production';

        $response = $this->post('/login', [
            'username' => 'apangcatan',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/');
    }

    public function test_the_shared_auth_user_prop_has_no_credentials(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page
            ->where('auth.user.id', $user->id)
            ->where('auth.user.role', 'staff')
            ->missing('auth.user.password')
            ->missing('auth.user.email'));
    }

    public function test_authenticated_users_can_reach_the_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Dashboard/Index'));
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/login');
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        User::factory()->create(['username' => 'apangcatan']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'apangcatan', 'password' => 'wrong-password'])
                ->assertSessionHasErrors('username');
        }

        // Even the correct password is refused while locked out; the key is
        // case-insensitive on the username so casing can't dodge the limit.
        $this->post('/login', ['username' => 'APangcatan', 'password' => 'password'])
            ->assertSessionHasErrors('username');
        $this->assertStringContainsString('try again', session('errors')->first('username'));
        $this->post('/login', ['username' => 'apangcatan', 'password' => 'password'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_a_successful_login_clears_the_failed_attempt_counter(): void
    {
        User::factory()->create(['username' => 'apangcatan']);

        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['username' => 'apangcatan', 'password' => 'wrong-password']);
        }

        $this->post('/login', ['username' => 'apangcatan', 'password' => 'password']);
        $this->assertAuthenticated();
        $this->post('/logout');

        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['username' => 'apangcatan', 'password' => 'wrong-password']);
        }

        $this->post('/login', ['username' => 'apangcatan', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }
}
