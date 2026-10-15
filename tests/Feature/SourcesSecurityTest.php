<?php

namespace InertiaPixel\LaravelLogViewer\Tests\Feature;

use InertiaPixel\LaravelLogViewer\Sources\LogFile;
use InertiaPixel\LaravelLogViewer\Sources\LogPathResolver;
use InertiaPixel\LaravelLogViewer\Sources\LogSourceRepository;
use InertiaPixel\LaravelLogViewer\Support\PathGuard;
use InertiaPixel\LaravelLogViewer\Tests\TestCase;

class SourcesSecurityTest extends TestCase
{
    private function names(): array
    {
        $this->app->forgetScopedInstances();

        return array_values(array_map(fn (LogFile $file) => $file->name, $this->app->make(LogSourceRepository::class)->all()));
    }

    public function test_only_matching_files_in_allowed_directories_are_listed(): void
    {
        $this->writeLog('laravel.log', 'x');
        $this->writeLog('worker.log', 'x');
        $this->writeLog('notes.txt', 'x');
        $this->writeLog('.env', 'APP_KEY=secret');
        $this->writeLog('.hidden.log', 'x');
        $this->writeLog('nested/deep.log', 'x');

        $this->assertEqualsCanonicalizing(['laravel.log', 'worker.log'], $this->names());
    }

    public function test_recursive_scanning_is_opt_in_and_skips_hidden_directories(): void
    {
        $this->writeLog('nested/deep.log', 'x');
        $this->writeLog('.git/objects.log', 'x');

        config()->set('log-viewer.sources.recursive', true);

        $this->assertSame(['nested/deep.log'], $this->names());
    }

    public function test_symlinks_escaping_the_directory_are_rejected(): void
    {
        $outside = sys_get_temp_dir().'/ipx-outside-'.bin2hex(random_bytes(4));
        mkdir($outside);
        file_put_contents($outside.'/secret.log', 'secret');
        file_put_contents($outside.'/.env', 'APP_KEY=secret');

        try {
            $this->writeLog('real.log', 'x');
            symlink($outside.'/secret.log', $this->logDirectory().'/escape.log');
            symlink($outside.'/.env', $this->logDirectory().'/env.log');
            symlink($outside, $this->logDirectory().'/linked-dir');
            symlink($this->logDirectory().'/real.log', $this->logDirectory().'/alias.log');

            config()->set('log-viewer.sources.recursive', true);

            // The in-directory alias resolves to real.log (listed once); escapes are dropped.
            $this->assertSame(['real.log'], $this->names());
        } finally {
            @unlink($outside.'/secret.log');
            @unlink($outside.'/.env');
            @rmdir($outside);
        }
    }

    public function test_empty_configuration_means_no_files(): void
    {
        $this->writeLog('laravel.log', 'x');

        config()->set('log-viewer.sources.directories', []);
        $this->assertSame([], $this->names());

        config()->set('log-viewer.sources.directories', [$this->logDirectory()]);
        config()->set('log-viewer.sources.patterns', []);
        $this->assertSame([], $this->names());
    }

    public function test_dangerous_roots_and_paths_are_refused(): void
    {
        $this->assertNull(PathGuard::directory('/'));
        $this->assertNull(PathGuard::directory(''));
        $this->assertNull(PathGuard::directory(null));
        $this->assertNull(PathGuard::directory("/tmp\0/evil"));
        $this->assertNull(PathGuard::file('phar://'.__FILE__));
        $this->assertNull(PathGuard::file('php://filter/resource=/etc/passwd'));
        $this->assertNull(PathGuard::file($this->writeLog('.env', 'x')));
        $this->assertFalse(PathGuard::isWithin('/var/log-evil/x.log', '/var/log'));
        $this->assertTrue(PathGuard::isWithin('/var/log/x.log', '/var/log'));
        $this->assertFalse(PathGuard::isWithin('/var/log', '/var/log'));

        config()->set('log-viewer.sources.directories', ['/']);
        $this->assertSame([], $this->names());
    }

    public function test_explicit_files_and_logging_channels(): void
    {
        $other = sys_get_temp_dir().'/ipx-channel-'.bin2hex(random_bytes(4));
        mkdir($other);
        file_put_contents($other.'/app.log', 'x');
        file_put_contents($other.'/app-2026-10-15.log', 'x');
        file_put_contents($other.'/unrelated.log', 'x');
        file_put_contents($other.'/single.log', 'x');

        try {
            config()->set('log-viewer.sources.directories', []);
            config()->set('log-viewer.sources.include_logging_channels', true);
            config()->set('logging.channels.daily', ['driver' => 'daily', 'path' => $other.'/app.log']);
            config()->set('logging.channels.single', ['driver' => 'single', 'path' => $other.'/single.log']);
            config()->set('logging.channels.stack', ['driver' => 'stack', 'channels' => ['daily']]);

            $this->assertEqualsCanonicalizing(['app.log', 'app-2026-10-15.log', 'single.log'], $this->names());

            config()->set('log-viewer.sources.include_logging_channels', false);
            config()->set('log-viewer.sources.files', [$other.'/unrelated.log', $other.'/missing.log', '../../etc/passwd']);
            $this->assertSame(['unrelated.log'], $this->names());
        } finally {
            array_map('unlink', glob($other.'/*') ?: []);
            rmdir($other);
        }
    }

    public function test_custom_resolver(): void
    {
        $path = $this->writeLog('sub/resolved.log', 'x');
        ResolverStub::$paths = [$path, '/etc/passwd-does-not-exist', $this->writeLog('.secret', 'x')];

        config()->set('log-viewer.sources.directories', []);
        config()->set('log-viewer.sources.resolver', ResolverStub::class);

        $this->assertSame(['resolved.log'], $this->names());
    }

    public function test_file_ids_are_opaque_and_only_known_ids_resolve(): void
    {
        $path = $this->writeLog('laravel.log', 'x');
        $repository = $this->app->make(LogSourceRepository::class);
        $file = $repository->find(LogFile::idFor($path));

        $this->assertSame('laravel.log', $file->name);
        $this->assertDoesNotMatchRegularExpression('#/#', $file->id);

        foreach (['../../.env', '/etc/passwd', 'laravel.log', str_repeat('0', 16), '', null, ['x']] as $input) {
            $this->assertNull($repository->find(is_string($input) || $input === null ? $input : null));
        }
    }

    public function test_the_entry_endpoint_cannot_read_arbitrary_files(): void
    {
        $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'info', 'x'));
        $this->actingAs($this->admin());

        $this->get('/log-viewer/entries/'.LogFile::idFor('/etc/passwd').'/0?h=00000000')->assertNotFound();
        $this->get('/log-viewer/entries/..%2F..%2F.env/0?h=00000000')->assertNotFound();
        $this->get('/log-viewer/entries/'.str_repeat('a', 16).'/0')->assertNotFound();
    }

    public function test_files_swapped_for_a_symlink_after_discovery_are_not_read(): void
    {
        $path = $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'info', 'original'));
        $repository = $this->app->make(LogSourceRepository::class);
        $file = $repository->all()[LogFile::idFor($path)];

        $outside = tempnam(sys_get_temp_dir(), 'ipx');
        file_put_contents($outside, 'secret');
        unlink($path);
        symlink($outside, $path);

        try {
            $this->expectException(\InertiaPixel\LaravelLogViewer\Exceptions\UnreadableLogFileException::class);
            (new \InertiaPixel\LaravelLogViewer\Reading\LogReader())->open($file);
        } finally {
            @unlink($path);
            @unlink($outside);
        }
    }

    public function test_absolute_paths_are_not_rendered_by_default(): void
    {
        $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'error', 'Failed at '.base_path('app/Models/User.php').':12'));

        $html = $this->actingAs($this->admin())->get('/log-viewer')->getContent();

        $this->assertStringNotContainsString($this->logDirectory(), $html);
        $this->assertStringNotContainsString(base_path(), $html);
        $this->assertStringContainsString('Failed at app/Models/User.php:12', $html);
    }
}

class ResolverStub implements LogPathResolver
{
    /** @var list<string> */
    public static array $paths = [];

    public function paths(): iterable
    {
        return self::$paths;
    }
}
