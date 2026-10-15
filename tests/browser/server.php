<?php

/**
 * Router for PHP's built-in server, used by the Playwright tests:
 *
 *   IPX_LOG_DIR=/tmp/logs php -S 127.0.0.1:8899 tests/browser/server.php
 *
 * Boots a Laravel application (Orchestra Testbench) with the package and
 * serves real requests. The "ipx_scenario" cookie selects a configuration:
 * default | embedded-tailwind | embedded-bootstrap | dark | auto-refresh | no-picker
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use InertiaPixel\LaravelLogViewer\LogViewer;
use InertiaPixel\LaravelLogViewer\LogViewerServiceProvider;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';

final class IpxBrowserTestProvider extends ServiceProvider
{
    public static string $scenario = 'default';

    public function register(): void
    {
        $config = $this->app['config'];
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $config->set('app.debug', true);
        $config->set('cache.default', 'array');
        $config->set('log-viewer.cache.store', 'array');
        $config->set('log-viewer.sources.include_logging_channels', false);
        $config->set('log-viewer.sources.directories', [getenv('IPX_LOG_DIR') ?: sys_get_temp_dir().'/ipx-log-viewer-browser']);
        $config->set('log-viewer.auth.require_authentication', false);
        $config->set('log-viewer.downloads.enabled', true);

        match (self::$scenario) {
            'embedded-tailwind' => $config->set('log-viewer.ui.layout', 'host.tailwind'),
            'embedded-bootstrap' => $config->set('log-viewer.ui.layout', 'host.bootstrap'),
            'dark' => $config->set('log-viewer.ui.theme', 'dark'),
            'auto-refresh' => $config->set('log-viewer.ui.auto_refresh', ['enabled' => true, 'interval' => 5]),
            'no-picker' => $config->set('log-viewer.filters.date_range.picker', false),
            default => null,
        };
    }

    public function boot(): void
    {
        LogViewer::authorizeUsing(fn () => true);
        View::addNamespace('host', __DIR__.'/host');
        View::addLocation(__DIR__);

        // Host pages that use common CSS frameworks' element styles, to check isolation.
        Route::get('/host/{framework}', fn (string $framework) => view('host::page', ['framework' => $framework]))
            ->where('framework', 'tailwind|bootstrap|plain');
    }
}

IpxBrowserTestProvider::$scenario = preg_replace('/[^a-z-]/', '', $_COOKIE['ipx_scenario'] ?? 'default') ?: 'default';

$app = Application::create(options: [
    'extra' => [
        'providers' => [IpxBrowserTestProvider::class, LogViewerServiceProvider::class],
        'dont-discover' => ['*'],
    ],
]);

$kernel = $app->make(Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
