<?php

namespace InertiaPixel\LaravelLogViewer\Tests\Feature;

use Illuminate\Support\Facades\View;
use InertiaPixel\LaravelLogViewer\Sources\LogFile;
use InertiaPixel\LaravelLogViewer\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class DashboardTest extends TestCase
{
    public function test_dashboard_renders_entries_filters_and_charts(): void
    {
        $this->writeLog('laravel.log', $this->fixture('laravel-real.log'));

        $response = $this->actingAs($this->admin())->get('/log-viewer');

        $response->assertOk();
        $response->assertSee('Payment gateway timeout');
        $response->assertSee('RuntimeException');
        $response->assertSee('data-ipx-log-viewer', false);
        $response->assertSee('name="q"', false);
        $response->assertSee('name="levels[]"', false);
        $response->assertSee('data-ipx-lv-chart="trend"', false);
        $response->assertSee('data-ipx-lv-chart="levels"', false);
        $response->assertSee('role="img"', false);
        $response->assertSee('Show data table');
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        $response->assertSee('/log-viewer/assets/log-viewer.css?v=', false);
        $response->assertSee('/log-viewer/assets/log-viewer.js?v=', false);
    }

    public function test_results_fragment(): void
    {
        $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'error', 'Only in fragment'));

        $response = $this->actingAs($this->admin())->get('/log-viewer/results?levels[]=error');

        $response->assertOk();
        $response->assertHeader('X-IPX-Log-Viewer', 'fragment');
        $response->assertSee('Only in fragment');
        $this->assertStringNotContainsString('<html', $response->getContent());
        $this->assertStringNotContainsString('<form', $response->getContent());

        preg_match('/data-ipx-lv-state="([^"]+)"/', $response->getContent(), $state);
        $state = json_decode(html_entity_decode($state[1], ENT_QUOTES | ENT_HTML5), true);
        $this->assertSame(['error'], $state['levels']);
        $this->assertSame('?levels%5B0%5D=error', $state['query']);
    }

    public function test_chart_data_reflects_the_active_filters(): void
    {
        $this->writeLog('laravel.log', $this->line('2026-10-14 10:00:00', 'error', 'a').$this->line('2026-10-15 10:00:00', 'error', 'b').$this->line('2026-10-15 11:00:00', 'info', 'c'));

        $charts = function (string $query): array {
            $html = $this->actingAs($this->admin())->get('/log-viewer/results'.$query)->getContent();
            preg_match_all('/data-ipx-lv-chart="(\w+)" data-ipx-lv-chart-data="([^"]+)"/', $html, $matches, PREG_SET_ORDER);

            return array_combine(array_column($matches, 1), array_map(fn ($match) => json_decode(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5), true), $matches));
        };

        $all = $charts('');
        $levels = array_column($all['levels']['levels'], 'count', 'key');
        $this->assertSame(['error' => 2, 'info' => 1], array_filter($levels));
        $this->assertSame(3, array_sum(array_merge(...array_column($all['trend']['series'], 'data'))));
        $this->assertStringContainsString('3 entries', $all['trend']['summary']);

        $errors = $charts('?levels[]=error');
        $this->assertSame(['error' => 2], array_filter(array_column($errors['levels']['levels'], 'count', 'key')));
        $this->assertSame([2], array_values(array_filter(array_map('array_sum', array_column($errors['trend']['series'], 'data')))));

        $day = $charts('?preset=custom&from=2026-10-15&to=2026-10-15');
        $this->assertSame(['error' => 1, 'info' => 1], array_filter(array_column($day['levels']['levels'], 'count', 'key')));
    }

    public function test_empty_states(): void
    {
        $this->actingAs($this->admin())->get('/log-viewer')->assertOk()->assertSee('No log files are available');

        $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'info', 'x'));
        $this->actingAs($this->admin())->get('/log-viewer?q=nothing-matches')->assertOk()->assertSee('Nothing matches the current filters');
    }

    public function test_invalid_parameters_are_reported_not_fatal(): void
    {
        $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'info', 'still listed'));

        $this->actingAs($this->admin())
            ->get('/log-viewer?per_page=9999&sort=evil&from=yesterday&file=../../.env')
            ->assertOk()
            ->assertSee('Some filters were ignored')
            ->assertSee('still listed');
    }

    public function test_entry_detail_fragment_and_page(): void
    {
        $path = $this->writeLog('laravel.log', $this->fixture('laravel-real.log'));
        $html = $this->actingAs($this->admin())->get('/log-viewer?q=Payment+gateway')->getContent();

        preg_match('#data-ipx-lv-detail="([^"]+)"#', $html, $match);
        $url = html_entity_decode($match[1]);
        $this->assertStringContainsString('/log-viewer/entries/'.LogFile::idFor($path).'/', $url);

        $fragment = $this->get($url, ['X-IPX-Log-Viewer' => 'fragment']);
        $fragment->assertOk()->assertHeader('X-IPX-Log-Viewer', 'fragment');
        $fragment->assertSee('Stack trace');
        $fragment->assertSee('[previous exception]', false);
        $fragment->assertSee('&quot;order_id&quot;: &quot;A-1001&quot;', false);
        $this->assertStringNotContainsString('<html', $fragment->getContent());

        $page = $this->get($url);
        $page->assertOk()->assertSee('<html', false)->assertSee('Back to results');

        // A rewritten file invalidates the entry instead of showing another one.
        file_put_contents($path, $this->line('2026-10-16 10:00:00', 'info', 'rotated'));
        $this->get($url)->assertNotFound();
    }

    public function test_stack_traces_context_and_raw_can_be_hidden(): void
    {
        config()->set('log-viewer.ui.show_stack_traces', false);
        config()->set('log-viewer.ui.show_context', false);
        config()->set('log-viewer.ui.show_raw', false);
        config()->set('log-viewer.ui.copy_buttons', false);

        $this->writeLog('laravel.log', $this->fixture('laravel-real.log'));
        $html = $this->actingAs($this->admin())->get('/log-viewer?q=Payment+gateway')->getContent();
        preg_match('#data-ipx-lv-detail="([^"]+)"#', $html, $match);

        $detail = $this->get(html_entity_decode($match[1]), ['X-IPX-Log-Viewer' => 'fragment'])->getContent();

        $this->assertStringNotContainsString('#0 {main}', $detail);
        $this->assertStringNotContainsString('order_id', $detail);
        $this->assertStringNotContainsString('Raw entry', $detail);
        $this->assertStringNotContainsString('data-ipx-lv-copy', $detail);
        $this->assertStringContainsString('RuntimeException', $detail);
    }

    public function test_summary_charts_and_filters_can_be_disabled(): void
    {
        config()->set('log-viewer.ui.show_summary', false);
        config()->set('log-viewer.ui.show_charts', false);
        config()->set('log-viewer.filters.search.enabled', false);
        config()->set('log-viewer.filters.levels.enabled', false);
        config()->set('log-viewer.filters.date_range.enabled', false);
        config()->set('log-viewer.filters.files.enabled', false);

        $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'info', 'x'));
        $html = $this->actingAs($this->admin())->get('/log-viewer?q=nothing&levels[]=error')->getContent();

        $this->assertStringNotContainsString('data-ipx-lv-chart=', $html);
        $this->assertStringNotContainsString('ipx-log-viewer__stats', $html);
        $this->assertStringNotContainsString('name="q"', $html);
        $this->assertStringNotContainsString('name="levels[]"', $html);
        $this->assertStringNotContainsString('name="preset"', $html);
        $this->assertStringNotContainsString('name="file"', $html);
        $this->assertStringContainsString('Showing 1–1 of 1', $html, 'Disabled filters are ignored.');
    }

    public function test_optional_controls_are_not_rendered_when_disabled(): void
    {
        $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'info', 'x'));
        $html = $this->actingAs($this->admin())->get('/log-viewer')->getContent();

        $this->assertStringNotContainsString('data-ipx-lv-auto-refresh', $html);
        $this->assertStringNotContainsString('name="regex"', $html);
        $this->assertStringNotContainsString('Download file', $html);
        $this->assertStringNotContainsString('delete', strtolower(strip_tags($html)));

        config()->set('log-viewer.ui.auto_refresh.enabled', true);
        config()->set('log-viewer.filters.search.regex', true);
        $html = $this->actingAs($this->admin())->get('/log-viewer')->getContent();

        $this->assertStringContainsString('data-ipx-lv-auto-refresh', $html);
        $this->assertStringContainsString('name="regex"', $html);
    }

    public function test_client_configuration_contains_no_server_settings(): void
    {
        config()->set('log-viewer.privacy.redaction.patterns', ['/cust_\w+/' => '[C]']);
        config()->set('log-viewer.auth.guard', 'web');
        $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'info', 'x'));

        $html = $this->actingAs($this->admin())->get('/log-viewer')->getContent();
        preg_match('/data-ipx-log-viewer-config="([^"]+)"/', $html, $match);
        $config = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);

        $this->assertJson($config);
        $this->assertSame(['endpoints', 'autoRefresh', 'picker', 'labels'], array_keys(json_decode($config, true)));

        foreach (['cust_', $this->logDirectory(), 'guard', 'redact', 'scan_bytes', 'APP_KEY'] as $secret) {
            $this->assertStringNotContainsString($secret, $config);
        }
    }

    #[DefineEnvironment('useHostLayout')]
    public function test_renders_inside_the_host_layout(): void
    {
        $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'info', 'Embedded entry'));

        $html = $this->actingAs($this->admin())->get('/log-viewer')->getContent();

        $this->assertStringContainsString('<div id="host-shell">', $html);
        $this->assertStringContainsString('Embedded entry', $html);
        $this->assertStringContainsString('data-ipx-log-viewer-asset="css"', $html);
        $this->assertSame(1, substr_count($html, '<html'));
        $this->assertStringContainsString('<h2 class="ipx-log-viewer__title">', $html);
    }

    protected function useHostLayout($app): void
    {
        $app['config']->set('log-viewer.ui.layout', 'host-layout');
        $app['config']->set('log-viewer.ui.section', 'body');

        View::addLocation(__DIR__.'/views');
    }

    public function test_dashboard_works_with_no_published_configuration(): void
    {
        $this->app['config']->set('log-viewer', []);
        $this->app->forgetScopedInstances();
        $this->app['env'] = 'local';

        // Defaults: storage/logs, *.log, auth required, local-only access without a gate.
        $this->get('/log-viewer')->assertUnauthorized();
        $this->actingAs((new \Illuminate\Foundation\Auth\User())->forceFill(['id' => 1]))->get('/log-viewer')->assertOk();
    }

    public function test_a_partially_published_section_keeps_the_other_defaults(): void
    {
        $this->app['config']->set('log-viewer.ui', ['title' => 'Ops logs']);
        $this->app->forgetScopedInstances();
        $this->writeLog('laravel.log', $this->line('2026-10-15 10:00:00', 'info', 'x'));

        $html = $this->actingAs($this->admin())->get('/log-viewer')->getContent();

        $this->assertStringContainsString('<title>Ops logs</title>', $html);
        $this->assertStringContainsString('<option value="50" selected>50</option>', $html, 'ui.per_page still defaults to 50.');
    }
}
