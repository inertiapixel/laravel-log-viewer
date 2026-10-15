<?php

namespace InertiaPixel\LaravelLogViewer\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use InertiaPixel\LaravelLogViewer\LogViewer;
use InertiaPixel\LaravelLogViewer\Sources\LogFile;
use InertiaPixel\LaravelLogViewer\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class DownloadTest extends TestCase
{
    public function test_downloads_are_disabled_by_default(): void
    {
        $path = $this->writeLog('laravel.log', 'x');

        $this->actingAs($this->admin())->get('/log-viewer/files/'.LogFile::idFor($path).'/download')->assertNotFound();
    }

    #[DefineEnvironment('enableDownloads')]
    public function test_downloads_are_redacted_streams_of_allowlisted_files(): void
    {
        $path = $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'info', 'Login {"password":"hunter2"}'));

        $response = $this->actingAs($this->admin())->get('/log-viewer/files/'.LogFile::idFor($path).'/download');

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename=laravel.log');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('"password":"[REDACTED]"', $content);
        $this->assertStringNotContainsString('hunter2', $content);

        $this->get('/log-viewer/files/'.LogFile::idFor('/etc/passwd').'/download')->assertNotFound();
    }

    #[DefineEnvironment('enableDownloads')]
    public function test_downloads_mask_multiline_keys_and_withhold_overlong_lines(): void
    {
        $contents = "first line\r\n-----BEGIN RSA PRIVATE KEY-----\nMIIEowKEYBODYLINE1\nKEYBODYLINE2\n-----END RSA PRIVATE KEY-----\n"
            .'long {"token":"'.str_repeat('x', 5 * 1024 * 1024).'SECRETTAIL"}'."\nlast password=hunter2";
        $path = $this->writeLog('laravel.log', $contents);

        $content = $this->actingAs($this->admin())->get('/log-viewer/files/'.LogFile::idFor($path).'/download')->streamedContent();

        $this->assertStringStartsWith("first line\r\n[REDACTED]\n", $content);
        $this->assertStringNotContainsString('KEYBODYLINE', $content);
        $this->assertStringNotContainsString('SECRETTAIL', $content);
        $this->assertStringContainsString("[line withheld: longer than 4 MB]\n", $content);
        $this->assertStringEndsWith('last password=[REDACTED]', $content);
    }

    #[DefineEnvironment('enableDownloads')]
    public function test_downloads_require_authorization_and_the_download_gate(): void
    {
        $path = $this->writeLog('laravel.log', 'x');
        $url = '/log-viewer/files/'.LogFile::idFor($path).'/download';
        $user = (new User())->forceFill(['id' => 1]);

        $this->get($url)->assertUnauthorized();

        LogViewer::authorizeUsing(fn ($request, $user, $ability) => $ability === 'view');
        $this->actingAs($user)->get($url)->assertForbidden();

        LogViewer::authorizeUsing(null);
        $this->app['env'] = 'production';
        Gate::define('viewLogViewer', fn () => true);
        Gate::define('downloadLogViewerFiles', fn () => false);
        $this->actingAs($user)->get($url)->assertForbidden();

        Gate::define('downloadLogViewerFiles', fn () => true);
        $this->actingAs($user)->get($url)->assertOk();
    }

    protected function enableDownloads($app): void
    {
        $app['config']->set('log-viewer.downloads.enabled', true);
    }

    #[DefineEnvironment('enableRawDownloads')]
    public function test_unredacted_downloads_are_opt_in(): void
    {
        $path = $this->writeLog('laravel.log', 'password=hunter2');

        $content = $this->actingAs($this->admin())->get('/log-viewer/files/'.LogFile::idFor($path).'/download')->streamedContent();

        $this->assertSame('password=hunter2', $content);
    }

    protected function enableRawDownloads($app): void
    {
        $app['config']->set('log-viewer.downloads', ['enabled' => true, 'redact' => false]);
    }
}
