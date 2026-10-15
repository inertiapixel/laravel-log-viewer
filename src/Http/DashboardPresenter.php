<?php

namespace InertiaPixel\LaravelLogViewer\Http;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Response;
use InertiaPixel\LaravelLogViewer\LogViewerServiceProvider;
use InertiaPixel\LaravelLogViewer\Query\DatePresets;
use InertiaPixel\LaravelLogViewer\Query\FilterFactory;
use InertiaPixel\LaravelLogViewer\Query\LogEntry;
use InertiaPixel\LaravelLogViewer\Query\LogFilters;
use InertiaPixel\LaravelLogViewer\Query\LogQuery;
use InertiaPixel\LaravelLogViewer\Query\QueryResult;
use InertiaPixel\LaravelLogViewer\Sources\LogSourceRepository;
use InertiaPixel\LaravelLogViewer\Support\AssetManager;
use InertiaPixel\LaravelLogViewer\Support\Level;
use InertiaPixel\LaravelLogViewer\Support\Redactor;
use InertiaPixel\LaravelLogViewer\Support\Settings;

/**
 * Builds view data. Only presentation-safe values reach the views and the
 * browser: no absolute paths (unless explicitly enabled), patterns, guard
 * names or other server-side settings.
 */
final class DashboardPresenter
{
    private const VIEW = LogViewerServiceProvider::NAME.'::';

    public function __construct(
        private readonly Settings $settings,
        private readonly LogSourceRepository $sources,
        private readonly FilterFactory $filters,
        private readonly DatePresets $presets,
        private readonly AssetManager $assets,
        private readonly Redactor $redactor,
        private readonly ViewFactory $views,
        private readonly Translator $translator,
        private readonly DateTimeZone $timezone,
    ) {
    }

    /**
     * @param  array<string, string>  $errors
     */
    public function page(LogFilters $filters, array $errors, QueryResult $result): Response
    {
        $data = $this->dashboardData($filters, $errors, $result);

        return $this->respond('partials.dashboard', $data);
    }

    /**
     * @param  array<string, string>  $errors
     */
    public function fragment(LogFilters $filters, array $errors, QueryResult $result): Response
    {
        $html = $this->views->make(self::VIEW.'partials.results', $this->dashboardData($filters, $errors, $result))->render();

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-IPX-Log-Viewer' => 'fragment',
        ]);
    }

    public function entry(LogEntry $entry, bool $fragment): Response
    {
        $data = ['entry' => $entry, 'vm' => $this->common()];

        if ($fragment) {
            return new Response($this->views->make(self::VIEW.'partials.entry-detail', $data)->render(), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-IPX-Log-Viewer' => 'fragment',
            ]);
        }

        return $this->respond('partials.entry-page', $data);
    }

    /**
     * Shared by every view.
     *
     * @return array<string, mixed>
     */
    public function common(): array
    {
        $name = $this->settings->string('route.name');
        $back = $this->settings->get('ui.back_url');

        return [
            'title' => $this->settings->string('ui.title') ?: $this->translator->get(self::VIEW.'log-viewer.title'),
            'theme' => in_array($this->settings->string('ui.theme'), ['light', 'dark', 'auto'], true) ? $this->settings->string('ui.theme') : 'auto',
            'styles' => $this->assets->styles(),
            'scripts' => $this->assets->scripts(),
            'routes' => [
                'index' => route($name.'index'),
                'results' => route($name.'results'),
                'entry' => $name.'entry',
                'download' => $this->settings->bool('downloads.enabled') ? $name.'download' : null,
            ],
            'backUrl' => is_string($back) && $back !== '' ? $back : null,
            'timezone' => $this->timezone->getName(),
            'dateFormat' => $this->settings->string('ui.date_format', 'Y-m-d H:i:s'),
            'features' => [
                'stackTraces' => $this->settings->bool('ui.show_stack_traces'),
                'context' => $this->settings->bool('ui.show_context'),
                'raw' => $this->settings->bool('ui.show_raw'),
                'copy' => $this->settings->bool('ui.copy_buttons'),
                'filePaths' => $this->settings->bool('ui.show_file_paths'),
            ],
            'maxEntrySize' => LogQuery::bytes($this->settings->int('limits.max_entry_bytes', 1024)),
        ];
    }

    public function formatTime(?int $timestamp, ?string $format = null): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp($timestamp, $this->timezone)->format($format ?? $this->settings->string('ui.date_format', 'Y-m-d H:i:s'));
    }

    /**
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private function dashboardData(LogFilters $filters, array $errors, QueryResult $result): array
    {
        $common = $this->common();
        $files = $this->sources->all();
        $levels = $this->filters->levels();
        $index = $common['routes']['index'];

        $layout = $this->settings->get('ui.layout');

        $vm = array_merge($common, [
            'layout' => is_string($layout) && $layout !== '' ? $layout : null,
            'filters' => $filters,
            'errors' => $errors,
            'result' => $result,
            'presenter' => $this,
            'files' => array_values($files),
            'totalFiles' => count($files),
            'levels' => $levels,
            'channels' => $this->channelOptions(array_merge($result->availableChannels, $filters->channel === null ? [] : [$filters->channel])),
            'presets' => $this->presetOptions(),
            'perPageOptions' => $this->filters->perPageOptions(),
            'sorts' => FilterFactory::SORTS,
            'enabled' => [
                'search' => $this->settings->bool('filters.search.enabled'),
                'caseToggle' => $this->settings->bool('filters.search.case_sensitive_toggle'),
                'traceToggle' => $this->settings->bool('filters.search.stack_trace_toggle'),
                'regex' => $this->settings->bool('filters.search.regex'),
                'levels' => $this->settings->bool('filters.levels.enabled'),
                'files' => $this->settings->bool('filters.files.enabled'),
                'channels' => $this->settings->bool('filters.channels.enabled'),
                'dateRange' => $this->settings->bool('filters.date_range.enabled'),
                'summary' => $this->settings->bool('ui.show_summary'),
                'charts' => $this->settings->bool('ui.show_charts'),
                'autoRefresh' => $this->settings->bool('ui.auto_refresh.enabled'),
            ],
            'autoRefreshInterval' => $this->settings->int('ui.auto_refresh.interval', 5, 86400),
            'searchMaxLength' => $this->settings->int('filters.search.max_length', 1, 1000),
            'resetUrl' => $index.'?'.http_build_query(['preset' => DatePresets::ALL]),
            'activeFilters' => $this->activeFilters($filters, $files, $index),
            'dateHint' => $this->dateHint($filters),
            'charts' => $this->chartData($result),
            'state' => $this->state($filters),
        ]);

        $vm['client'] = $this->clientConfig($vm);

        return ['vm' => $vm];
    }

    /**
     * Channel names offered in the filter: only names that are valid filter
     * values and that redaction leaves unchanged (channel names come from
     * log content).
     *
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function channelOptions(array $channels): array
    {
        return array_values(array_unique(array_filter(
            $channels,
            fn (string $channel) => preg_match('/^[\w.\-]{1,100}$/', $channel) === 1 && $this->redactor->apply($channel) === $channel,
        )));
    }

    /**
     * Presets as [key => label], "Any time" first and "Custom range" last.
     *
     * @return array<string, string>
     */
    private function presetOptions(): array
    {
        $options = [DatePresets::ALL => $this->label('presets.all')];

        foreach ($this->presets->keys() as $key) {
            $options[$key] = $this->presets->label($key) ?? $this->label('presets.'.$key);
        }

        $options[DatePresets::CUSTOM] = $this->label('presets.custom');

        return $options;
    }

    /**
     * Chips describing each active filter, each with a URL that removes it.
     *
     * @param  array<string, \InertiaPixel\LaravelLogViewer\Sources\LogFile>  $files
     * @return list<array{label: string, value: string, url: string}>
     */
    private function activeFilters(LogFilters $filters, array $files, string $index): array
    {
        $url = fn (array $without) => $index.$this->queryString($filters->query(array_merge(['page' => null], $without)));
        $chips = [];

        if ($filters->search !== '') {
            $chips[] = ['label' => $this->label('fields.q'), 'value' => $filters->search, 'url' => $url(['q' => null])];
        }

        foreach ($filters->levels as $level) {
            $remaining = array_values(array_diff($filters->levels, [$level]));
            $chips[] = ['label' => $this->label('fields.levels'), 'value' => $this->label('levels.'.$level), 'url' => $url(['levels' => $remaining === [] ? null : $remaining])];
        }

        if ($filters->file !== null && isset($files[$filters->file])) {
            $chips[] = ['label' => $this->label('fields.file'), 'value' => $files[$filters->file]->name, 'url' => $url(['file' => 'all'])];
        }

        if ($filters->channel !== null) {
            $chips[] = ['label' => $this->label('fields.channel'), 'value' => $filters->channel, 'url' => $url(['channel' => null])];
        }

        if ($filters->preset !== DatePresets::ALL) {
            $value = $filters->preset === DatePresets::CUSTOM
                ? trim(($filters->fromDate ?? '…').' – '.($filters->toDate ?? '…'))
                : ($this->presetOptions()[$filters->preset] ?? $filters->preset);
            $chips[] = ['label' => $this->label('fields.preset'), 'value' => $value, 'url' => $url(['preset' => DatePresets::ALL, 'from' => null, 'to' => null])];
        }

        return $chips;
    }

    private function dateHint(LogFilters $filters): ?string
    {
        if ($filters->from === null && $filters->to === null) {
            return null;
        }

        return trim(($filters->from === null ? '…' : $this->presets->format($filters->from)).' – '.($filters->to === null ? '…' : $this->presets->format($filters->to)));
    }

    /**
     * @return array<string, mixed>
     */
    private function chartData(QueryResult $result): array
    {
        $trend = null;

        if ($result->trend !== null && $result->total > 0) {
            $buckets = $result->trend['buckets'];
            $trend = [
                'interval' => $this->translator->get(self::VIEW.'log-viewer.charts.intervals.'.$result->trend['interval']),
                'labels' => array_column($buckets, 'label'),
                'series' => [
                    ['key' => 'other', 'label' => $this->label('charts.other'), 'data' => array_column($buckets, 'other')],
                    ['key' => 'warnings', 'label' => $this->label('charts.warnings'), 'data' => array_column($buckets, 'warnings')],
                    ['key' => 'errors', 'label' => $this->label('charts.errors'), 'data' => array_column($buckets, 'errors')],
                ],
            ];
        }

        if ($trend !== null) {
            $totals = array_map(fn (array $bucket) => $bucket['errors'] + $bucket['warnings'] + $bucket['other'], $result->trend['buckets']);
            $peak = (int) array_search(max($totals), $totals, true);
            $trend['summary'] = $this->translator->get(self::VIEW.'log-viewer.charts.trend_summary', [
                'total' => number_format($result->total),
                'peak' => $trend['labels'][$peak],
                'count' => number_format($totals[$peak]),
            ]);
        }

        $levels = [];
        $parts = [];

        foreach (array_merge(Level::all(), [Level::UNKNOWN]) as $level) {
            $count = $result->levels[$level] ?? 0;

            if ($level !== Level::UNKNOWN || $count > 0) {
                $levels[] = ['key' => $level, 'label' => $this->label('levels.'.$level), 'count' => $count];

                if ($count > 0) {
                    $parts[] = $this->label('levels.'.$level).': '.number_format($count);
                }
            }
        }

        return [
            'trend' => $trend,
            'levels' => $levels,
            'levelsSummary' => $this->label('charts.levels').'. '.($parts === [] ? $this->label('charts.empty') : implode(', ', $parts)),
        ];
    }

    /**
     * Current filters for the browser (form sync, history).
     *
     * @return array<string, mixed>
     */
    private function state(LogFilters $filters): array
    {
        return [
            'file' => $filters->file ?? 'all',
            'levels' => $filters->levels,
            'channel' => $filters->channel ?? '',
            'q' => $filters->search,
            'case' => $filters->caseSensitive,
            'regex' => $filters->regex,
            'traces' => $filters->searchTraces,
            'preset' => $filters->preset,
            'from' => $filters->fromDate ?? '',
            'to' => $filters->toDate ?? '',
            'sort' => $filters->sort,
            'per_page' => $filters->perPage,
            'page' => $filters->page,
            'query' => $this->queryString($filters->query()),
        ];
    }

    /**
     * Configuration for the browser: UI options and translated labels only.
     *
     * @param  array<string, mixed>  $vm
     * @return array<string, mixed>
     */
    private function clientConfig(array $vm): array
    {
        $presets = [];

        foreach ($vm['presets'] as $key => $label) {
            $range = in_array($key, [DatePresets::ALL, DatePresets::CUSTOM], true) ? null : $this->presets->resolve($key);
            $presets[] = [
                'key' => $key,
                'label' => $label,
                'from' => $range === null ? null : $this->presets->format($range[0]),
                'to' => $range === null ? null : $this->presets->format($range[1]),
            ];
        }

        return [
            'endpoints' => ['results' => $vm['routes']['results'], 'index' => $vm['routes']['index']],
            'autoRefresh' => $vm['enabled']['autoRefresh'] ? $vm['autoRefreshInterval'] : 0,
            'picker' => [
                'enabled' => $vm['enabled']['dateRange'] && $this->settings->bool('filters.date_range.picker'),
                'months' => $this->settings->int('filters.date_range.months', 1, 2),
                'firstWeekday' => $this->settings->int('filters.date_range.first_weekday', 0, 6),
                'locale' => str_replace('_', '-', (string) $this->translator->getLocale()),
                'today' => CarbonImmutable::now($this->timezone)->format('Y-m-d'),
                'presets' => $presets,
            ],
            'labels' => [
                'loading' => $this->label('states.loading'),
                'error' => $this->label('states.error'),
                'session' => $this->label('states.session'),
                'forbidden' => $this->label('states.forbidden'),
                'retry' => $this->label('states.retry'),
                'updated' => $this->label('states.updated'),
                'copied' => $this->label('detail.copied'),
                'copyFailed' => $this->label('detail.copy_failed'),
                'detailLoading' => $this->label('detail.loading'),
                'detailFailed' => $this->label('detail.load_failed'),
                'showDetails' => $this->label('table.show_details'),
                'hideDetails' => $this->label('table.hide_details'),
                'invalidDate' => $this->label('errors.invalid_date'),
                'rangeOrder' => $this->label('errors.range_order'),
                'chartsUnavailable' => $this->label('charts.unavailable'),
                'calendarHint' => $this->label('filters.calendar_hint'),
                'paused' => $this->label('auto_refresh_paused'),
                'allChannels' => $this->label('filters.all_channels'),
                'entries' => $this->label('charts.count'),
                'calendar' => $this->label('filters.calendar'),
                'calendarNavigation' => $this->label('filters.calendar_navigation'),
                'previousMonth' => $this->label('filters.previous_month'),
                'nextMonth' => $this->label('filters.next_month'),
                'selectedRange' => $this->label('filters.selected_range'),
                'dateFormat' => $this->label('filters.date_format_hint'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function queryString(array $query): string
    {
        return $query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function label(string $key): string
    {
        return (string) $this->translator->get(self::VIEW.'log-viewer.'.$key);
    }

    /**
     * Render a page partial inside the standalone document, or inside the
     * application's own layout when log-viewer.ui.layout is set.
     *
     * @param  array<string, mixed>  $data
     */
    private function respond(string $inner, array $data): Response
    {
        $layout = $this->settings->get('ui.layout');
        $data['vm']['layout'] = is_string($layout) && $layout !== '' ? $layout : null;
        $data['vm']['section'] = $this->settings->string('ui.section', 'content');
        $data['inner'] = self::VIEW.$inner;

        $wrapper = $data['vm']['layout'] === null ? 'standalone' : 'embedded';

        return new Response($this->views->make(self::VIEW.$wrapper, $data)->render(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
