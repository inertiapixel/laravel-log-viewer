<?php

namespace InertiaPixel\LaravelLogViewer\Tests\Unit;

use Illuminate\Support\ServiceProvider;
use InertiaPixel\LaravelLogViewer\LogViewerServiceProvider;
use PHPUnit\Framework\TestCase;

class ComposerMetadataTest extends TestCase
{
    /** @return array<string, mixed> */
    private function composer(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_package_identity_and_autoloading(): void
    {
        $composer = $this->composer();

        $this->assertSame('inertiapixel/laravel-log-viewer', $composer['name']);
        $this->assertSame('library', $composer['type']);
        $this->assertSame('MIT', $composer['license']);
        $this->assertSame(['InertiaPixel\\LaravelLogViewer\\' => 'src/'], $composer['autoload']['psr-4']);
    }

    public function test_service_provider_is_auto_discovered(): void
    {
        $providers = $this->composer()['extra']['laravel']['providers'];

        $this->assertSame([LogViewerServiceProvider::class], $providers);
        $this->assertTrue(is_subclass_of($providers[0], ServiceProvider::class));
    }

    public function test_runtime_dependencies_are_limited_to_php_and_laravel(): void
    {
        foreach (array_keys($this->composer()['require']) as $package) {
            $this->assertMatchesRegularExpression('/^(php|ext-[a-z]+|illuminate\/[a-z-]+)$/', $package, "Unexpected runtime dependency [{$package}].");
        }
    }

    public function test_every_class_lives_in_the_package_namespace(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src'));

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $this->assertMatchesRegularExpression('/^namespace InertiaPixel\\\\LaravelLogViewer(\\\\[A-Za-z]+)*;$/m', (string) file_get_contents($file->getPathname()), $file->getFilename());
            }
        }
    }

    public function test_dist_archives_ship_compiled_assets_but_not_dev_files(): void
    {
        $attributes = (string) file_get_contents(dirname(__DIR__, 2).'/.gitattributes');

        $this->assertStringNotContainsString('/resources/dist export-ignore', $attributes);

        foreach (['/tests', '/package.json', '/build.mjs', '/phpunit.xml.dist'] as $devFile) {
            $this->assertMatchesRegularExpression('#^'.preg_quote($devFile, '#').'\s+export-ignore$#m', $attributes);
        }
    }

    public function test_no_css_framework_is_used(): void
    {
        $package = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/package.json'), true, 512, JSON_THROW_ON_ERROR);
        $dependencies = array_keys(array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []));

        foreach (['tailwindcss', 'bootstrap', 'bulma', 'foundation-sites', '@tailwindcss/vite', 'postcss'] as $framework) {
            $this->assertNotContains($framework, $dependencies);
        }

        $this->assertSame(['@playwright/test', 'chart.js', 'esbuild', 'vanilla-calendar-pro'], $dependencies);
    }
}
