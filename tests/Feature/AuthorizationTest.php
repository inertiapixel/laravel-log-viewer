<?php

namespace InertiaPixel\LaravelLogViewer\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use InertiaPixel\LaravelLogViewer\LogViewer;
use InertiaPixel\LaravelLogViewer\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class AuthorizationTest extends TestCase
{
    private function user(int $id = 1, string $email = 'user@example.com'): User
    {
        $user = new User();
        $user->forceFill(['id' => $id, 'email' => $email]);

        return $user;
    }

    public function test_guests_get_401_when_no_login_route_exists(): void
    {
        $this->get('/log-viewer')->assertUnauthorized();
        $this->get('/log-viewer/results')->assertUnauthorized();
    }

    public function test_guests_get_json_401_for_ajax_requests(): void
    {
        $this->getJson('/log-viewer/results')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_guests_are_redirected_to_the_login_route_when_it_exists(): void
    {
        Route::get('/login', fn () => 'login')->name('login');
        Route::getRoutes()->refreshNameLookups();

        $this->get('/log-viewer')->assertRedirect('/login');
    }

    public function test_without_a_gate_or_callback_access_is_limited_to_local(): void
    {
        $this->app['env'] = 'production';
        $this->actingAs($this->user())->get('/log-viewer')->assertForbidden();

        $this->app['env'] = 'local';
        $this->actingAs($this->user())->get('/log-viewer')->assertOk();
    }

    public function test_the_host_gate_decides(): void
    {
        $this->app['env'] = 'production';
        Gate::define('viewLogViewer', fn (User $user) => $user->email === 'admin@example.com');

        $this->actingAs($this->user(1, 'user@example.com'))->get('/log-viewer')->assertForbidden();
        $this->actingAs($this->user(2, 'admin@example.com'))->get('/log-viewer')->assertOk();
    }

    public function test_the_gate_name_is_configurable(): void
    {
        $this->app['env'] = 'production';
        config()->set('log-viewer.auth.gate', 'admin-tools');
        Gate::define('admin-tools', fn (User $user) => true);

        $this->actingAs($this->user())->get('/log-viewer')->assertOk();
    }

    public function test_the_callback_wins_over_the_gate(): void
    {
        Gate::define('viewLogViewer', fn () => true);
        LogViewer::authorizeUsing(fn ($request, $user, $ability) => $ability === 'view' && $user?->getAuthIdentifier() === 7);

        $this->actingAs($this->user(1))->get('/log-viewer')->assertForbidden();
        $this->actingAs($this->user(7))->get('/log-viewer')->assertOk();
    }

    public function test_forbidden_ajax_requests_get_json(): void
    {
        LogViewer::authorizeUsing(fn () => false);

        $this->actingAs($this->user())->getJson('/log-viewer/results')->assertForbidden()->assertJsonStructure(['message']);
    }

    #[DefineEnvironment('useAdminGuard')]
    public function test_a_custom_guard_is_used(): void
    {
        LogViewer::authorizeUsing(fn ($request, $user) => $user !== null);

        // Authenticated on the default "web" guard only: not enough.
        $this->actingAs($this->user(), 'web')->get('/log-viewer')->assertUnauthorized();

        $this->actingAs($this->user(), 'admin')->get('/log-viewer')->assertOk();
    }

    protected function useAdminGuard($app): void
    {
        $app['config']->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('log-viewer.auth.guard', 'admin');
    }

    public function test_authentication_can_be_delegated_to_other_middleware(): void
    {
        config()->set('log-viewer.auth.require_authentication', false);
        LogViewer::authorizeUsing(fn ($request, $user) => $request->header('X-Internal') === 'yes');

        $this->get('/log-viewer')->assertForbidden();
        $this->get('/log-viewer', ['X-Internal' => 'yes'])->assertOk();
    }

    #[DefineEnvironment('useRoleMiddleware')]
    public function test_host_middleware_runs_before_authorization(): void
    {
        $this->actingAs($this->admin())->get('/log-viewer')->assertStatus(418);
    }

    protected function useRoleMiddleware($app): void
    {
        $app['router']->aliasMiddleware('role', TeapotMiddleware::class);
        $app['config']->set('log-viewer.route.middleware', ['web', 'role:ops']);
    }

    public function test_every_route_requires_authorization_except_assets(): void
    {
        LogViewer::authorizeUsing(fn () => false);
        $this->writeLog('a.log', $this->line('2026-10-15 10:00:00', 'info', 'x'));

        foreach (['/log-viewer', '/log-viewer/results', '/log-viewer/entries/'.str_repeat('a', 16).'/0?h=00000000'] as $uri) {
            $this->actingAs($this->user())->get($uri)->assertForbidden();
        }

        $this->get('/log-viewer/assets/log-viewer.css')->assertOk();
    }
}

class TeapotMiddleware
{
    public function handle($request, \Closure $next, string $role)
    {
        abort(418);
    }
}
