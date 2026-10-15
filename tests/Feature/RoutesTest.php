<?php

namespace InertiaPixel\LaravelLogViewer\Tests\Feature;

use Illuminate\Support\Facades\Route;
use InertiaPixel\LaravelLogViewer\Http\Middleware\Authorize;
use InertiaPixel\LaravelLogViewer\Http\Middleware\SecureHeaders;
use InertiaPixel\LaravelLogViewer\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class RoutesTest extends TestCase
{
    public function test_default_routes(): void
    {
        $this->assertSame(url('log-viewer'), route('inertiapixel.log-viewer.index'));
        $this->assertSame(url('log-viewer/results'), route('inertiapixel.log-viewer.results'));
        $this->assertTrue(Route::has('inertiapixel.log-viewer.entry'));
        $this->assertTrue(Route::has('inertiapixel.log-viewer.asset'));
        $this->assertFalse(Route::has('inertiapixel.log-viewer.download'), 'Downloads are disabled by default.');

        $route = Route::getRoutes()->getByName('inertiapixel.log-viewer.index');
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame(['web', SecureHeaders::class, Authorize::class], $route->gatherMiddleware());
    }

    public function test_every_route_is_read_only(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'inertiapixel.log-viewer.')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods(), $route->getName());
            }
        }

        $this->post('/log-viewer')->assertStatus(405);
        $this->delete('/log-viewer')->assertStatus(405);
    }

    #[DefineEnvironment('customRoutes')]
    public function test_prefix_name_domain_and_middleware_are_configurable(): void
    {
        $this->assertFalse(Route::has('inertiapixel.log-viewer.index'));
        $this->assertSame('http://ops.example.test/admin/logs', route('ops.logs.index'));

        $route = Route::getRoutes()->getByName('ops.logs.index');
        $this->assertSame('ops.example.test', $route->getDomain());
        $this->assertSame(['web', 'throttle:60,1', SecureHeaders::class, Authorize::class], $route->gatherMiddleware());
        $this->assertSame('http://ops.example.test/admin/logs/assets/log-viewer.js?v=x', preg_replace('/v=\w+/', 'v=x', route('ops.logs.asset', ['file' => 'log-viewer.js', 'v' => 'y'])));
    }

    protected function customRoutes($app): void
    {
        $app['config']->set('log-viewer.route', [
            'prefix' => '/admin/logs/',
            'name' => 'ops.logs.',
            'domain' => 'ops.example.test',
            'middleware' => ['web', 'throttle:60,1'],
        ]);
    }

    #[DefineEnvironment('disableRoutes')]
    public function test_routes_can_be_disabled(): void
    {
        $this->assertFalse(Route::has('inertiapixel.log-viewer.index'));
        $this->assertFalse(Route::has('inertiapixel.log-viewer.asset'));
    }

    protected function disableRoutes($app): void
    {
        $app['config']->set('log-viewer.route.enabled', false);
    }

    #[DefineEnvironment('disablePackage')]
    public function test_the_master_switch_disables_everything(): void
    {
        $this->assertFalse(Route::has('inertiapixel.log-viewer.index'));
    }

    protected function disablePackage($app): void
    {
        $app['config']->set('log-viewer.enabled', false);
    }

    public function test_responses_are_never_cached_or_framed(): void
    {
        $response = $this->actingAs($this->admin())->get('/log-viewer');

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        // Also on error responses produced by the authorization middleware.
        $this->assertStringContainsString('no-store', (string) $this->get('/log-viewer')->headers->get('Cache-Control'));
    }

    public function test_assets_are_served_with_long_lived_caching(): void
    {
        $response = $this->get(route('inertiapixel.log-viewer.asset', ['file' => 'log-viewer.js', 'v' => \InertiaPixel\LaravelLogViewer\Support\AssetManager::version('log-viewer.js')]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->getCookies()[0] ?? null, 'Assets do not start a session.');

        $this->get('/log-viewer/assets/other.js')->assertNotFound();
        $this->get('/log-viewer/assets/..%2F..%2Fcomposer.json')->assertNotFound();
    }

    #[DefineEnvironment('publishedAssets')]
    public function test_published_asset_mode(): void
    {
        $html = $this->actingAs($this->admin())->get('/log-viewer')->getContent();

        $this->assertMatchesRegularExpression('#https://cdn\.example\.test/ipx/inertiapixel-log-viewer\.css\?v=\w+#', $html);
    }

    protected function publishedAssets($app): void
    {
        $app['config']->set('log-viewer.assets', ['mode' => 'published', 'url' => 'https://cdn.example.test/ipx/']);
    }
}
