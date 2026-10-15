<?php

namespace InertiaPixel\LaravelLogViewer;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use InertiaPixel\LaravelLogViewer\Support\Settings;

/**
 * Authorization entry point.
 *
 * Register a callback in a service provider to decide who may use the
 * viewer:
 *
 *     LogViewer::authorizeUsing(fn (Request $request, $user, string $ability) => $user?->isAdmin());
 *
 * Without a callback, the configured Gate ability is used when the
 * application defines it; otherwise access is limited to the configured
 * local environments.
 */
final class LogViewer
{
    public const ABILITY_VIEW = 'view';

    public const ABILITY_DOWNLOAD = 'download';

    private static ?Closure $callback = null;

    /**
     * @param  (callable(Request, ?Authenticatable, string): bool)|null  $callback
     */
    public static function authorizeUsing(?callable $callback): void
    {
        self::$callback = $callback === null ? null : Closure::fromCallable($callback);
    }

    public static function check(Request $request, ?Authenticatable $user, string $ability = self::ABILITY_VIEW): bool
    {
        if (self::$callback !== null) {
            return (bool) (self::$callback)($request, $user, $ability);
        }

        $settings = app(Settings::class);

        if ($ability === self::ABILITY_DOWNLOAD) {
            $gate = $settings->string('auth.download_gate');

            if ($gate !== '' && Gate::has($gate)) {
                return Gate::forUser($user)->allows($gate, [$request]);
            }
        }

        $gate = $settings->string('auth.gate');

        if ($gate !== '' && Gate::has($gate)) {
            return Gate::forUser($user)->allows($gate, [$request]);
        }

        return App::environment($settings->strings('auth.local_environments'));
    }
}
