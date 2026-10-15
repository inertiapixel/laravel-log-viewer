<?php

namespace InertiaPixel\LaravelLogViewer\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use InertiaPixel\LaravelLogViewer\LogViewer;
use InertiaPixel\LaravelLogViewer\LogViewerServiceProvider;
use InertiaPixel\LaravelLogViewer\Tests\Concerns\CreatesLogFiles;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use CreatesLogFiles;

    protected function setUp(): void
    {
        parent::setUp();

        LogViewer::authorizeUsing(null);
    }

    protected function tearDown(): void
    {
        LogViewer::authorizeUsing(null);
        $this->removeLogDirectory();

        parent::tearDown();
    }

    /**
     * Each request starts with fresh scoped services, as a real request
     * (or an Octane request) would.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->forgetScopedInstances();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function getPackageProviders($app): array
    {
        return [LogViewerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('log-viewer.cache.store', 'array');
        // Logging channels of the test application are not log sources here.
        $app['config']->set('log-viewer.sources.include_logging_channels', false);
        $app['config']->set('log-viewer.sources.directories', [$this->logDirectory()]);
        $app['config']->set('log-viewer.filters.date_range.default', null);
    }

    /** A user that passes LogViewer authorization. */
    protected function admin(): Authenticatable
    {
        LogViewer::authorizeUsing(fn ($request, $user) => $user !== null);

        $user = new User();
        $user->forceFill(['id' => 1, 'name' => 'Admin', 'email' => 'admin@example.com']);

        return $user;
    }
}
