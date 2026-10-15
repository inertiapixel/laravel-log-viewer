<?php

namespace InertiaPixel\LaravelLogViewer\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InertiaPixel\LaravelLogViewer\LogViewer;
use InertiaPixel\LaravelLogViewer\Support\Settings;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Always appended to the viewer's routes, after the configured middleware.
 *
 * 1. Resolves the user from the configured guard (not necessarily "web").
 * 2. Guests: 401 for JSON/AJAX requests, otherwise a redirect to the
 *    application's guest redirect or "login" route, or a plain 401.
 * 3. Authorizes with LogViewer::check(): 403 when denied.
 */
final class Authorize
{
    public function handle(Request $request, Closure $next, string $ability = LogViewer::ABILITY_VIEW): Response
    {
        // Resolved per request: middleware instances may be reused (Octane).
        $settings = app(Settings::class);
        $guard = $settings->get('auth.guard');
        $guard = is_string($guard) && $guard !== '' ? $guard : null;
        $user = app(Auth::class)->guard($guard)->user();

        if ($user === null && $settings->bool('auth.require_authentication')) {
            $this->unauthenticated($request, $guard);
        }

        if (! LogViewer::check($request, $user, $ability)) {
            abort(403, 'You are not authorized to access the log viewer.');
        }

        return $next($request);
    }

    private function unauthenticated(Request $request, ?string $guard): never
    {
        $guards = $guard === null ? [] : [$guard];

        if ($request->expectsJson()) {
            throw new AuthenticationException('Unauthenticated.', $guards);
        }

        try {
            // The application's guest redirect (Laravel's default calls route('login')).
            $redirect = (new AuthenticationException())->redirectTo($request);
        } catch (Throwable) {
            $redirect = null;
        }

        $redirect ??= Route::has('login') ? route('login') : null;

        if (! is_string($redirect) || $redirect === '') {
            abort(401, 'Unauthenticated.');
        }

        throw new AuthenticationException('Unauthenticated.', $guards, $redirect);
    }
}
