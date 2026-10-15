<?php

namespace InertiaPixel\LaravelLogViewer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Static checks of the shipped CSS and JavaScript: everything is scoped
 * to the package prefix and nothing global is defined.
 */
class AssetIsolationTest extends TestCase
{
    private function root(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path === '' ? '' : '/'.$path);
    }

    /** @return list<string> every selector of a stylesheet, comments removed */
    private function selectors(string $css): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/(?:^|[{};])\s*([^{}@;]+?)\s*\{/', $css, $matches);
        $selectors = [];

        foreach ($matches[1] as $list) {
            // Split on commas outside parentheses (":where(h1, h2)" is one selector).
            foreach (preg_split('/,(?![^(]*\))/', $list) as $selector) {
                $selector = trim($selector);

                if ($selector !== '' && ! preg_match('/^(from|to|\d+%)$/', $selector)) {
                    $selectors[] = $selector;
                }
            }
        }

        return $selectors;
    }

    public function test_every_selector_is_scoped_to_the_package(): void
    {
        foreach (glob($this->root('resources/css/*.css')) as $file) {
            foreach ($this->selectors((string) file_get_contents($file)) as $selector) {
                $this->assertMatchesRegularExpression('/^\.ipx-log-viewer(-page)?(--[a-z-]+)?(?![\w-])/', $selector, "Unscoped selector [{$selector}] in ".basename($file).'.');
            }
        }
    }

    public function test_compiled_css_is_scoped_too(): void
    {
        $css = (string) file_get_contents($this->root('resources/dist/inertiapixel-log-viewer.css'));

        foreach ($this->selectors($css) as $selector) {
            $this->assertMatchesRegularExpression('/^\.ipx-log-viewer/', $selector, "Unscoped selector [{$selector}] in the compiled stylesheet.");
        }
    }

    public function test_no_global_custom_properties_or_framework_classes(): void
    {
        foreach (glob($this->root('resources/css/*.css')) as $file) {
            $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($file));

            $this->assertDoesNotMatchRegularExpression('/(^|[\s,}]):root\b/', $css, basename($file).' defines :root.');
            $this->assertDoesNotMatchRegularExpression('/[{;]\s*--(?!ipx-lv-)[a-z][\w-]*\s*:/', $css, basename($file).' defines an unprefixed custom property.');
            $this->assertDoesNotMatchRegularExpression('/@tailwind|@apply|--tw-/', $css, basename($file).' uses Tailwind.');
            $this->assertDoesNotMatchRegularExpression('/@keyframes\s+(?!ipx-log-viewer-)/', $css, basename($file).' defines an unprefixed animation.');
        }
    }

    public function test_markup_uses_only_prefixed_classes(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root('resources/views')));

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            preg_match_all('/class="([^"]*)"/', (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $list) {
                foreach (preg_split('/\s+/', trim((string) preg_replace('/\{\{.*?\}\}/', '', $list))) as $class) {
                    if ($class !== '') {
                        $this->assertMatchesRegularExpression('/^ipx-log-viewer(-page)?(__|--|$)/', $class, "Unprefixed class [{$class}] in {$file->getFilename()}.");
                    }
                }
            }

            $this->assertDoesNotMatchRegularExpression('/\sstyle="/', (string) file_get_contents($file->getPathname()), "Inline style in {$file->getFilename()}.");
        }
    }

    public function test_javascript_bundle_is_a_strict_iife_with_one_global(): void
    {
        $js = (string) file_get_contents($this->root('resources/dist/inertiapixel-log-viewer.js'));

        $this->assertStringContainsString('"use strict";', $js);
        $this->assertMatchesRegularExpression('/"use strict";\s*\(\(\)=>\{/', $js, 'The bundle is wrapped in an IIFE.');
        $this->assertStringContainsString('InertiaPixelLogViewer', $js);
        $this->assertGreaterThan(50000, strlen($js), 'Chart.js and the calendar are bundled.');

        foreach (glob($this->root('resources/js/*.js')) as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/window\.([A-Za-z_$][\w$]*)\s*=/', $source, $assignments);
            $this->assertSame([], $assignments[1], basename($file).' assigns a global.');
            $this->assertDoesNotMatchRegularExpression('/\b(eval|new Function|document\.write)\b/', $source, basename($file));
            $this->assertDoesNotMatchRegularExpression('/\.innerHTML\s*=(?!\s*(html\b|await response\.text\(\)))/', $source, basename($file).' sets innerHTML from something other than a server fragment.');
        }
    }
}
