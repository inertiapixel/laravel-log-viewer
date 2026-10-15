<?php

namespace InertiaPixel\LaravelLogViewer\Query;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use InertiaPixel\LaravelLogViewer\Sources\LogSourceRepository;
use InertiaPixel\LaravelLogViewer\Support\Level;
use InertiaPixel\LaravelLogViewer\Support\Settings;

/**
 * Validates and normalizes request input into LogFilters.
 *
 * The dashboard is driven by shareable GET URLs, so invalid parameters do
 * not abort the request: each invalid value is dropped (falling back to
 * its default) and reported back to the user as a message.
 */
final class FilterFactory
{
    public const SORTS = ['newest', 'oldest'];

    public function __construct(
        private readonly Settings $settings,
        private readonly LogSourceRepository $sources,
        private readonly DatePresets $presets,
        private readonly ValidationFactory $validation,
        private readonly Translator $translator,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: LogFilters, 1: array<string, string>}
     */
    public function make(array $input): array
    {
        $input = $this->prepare($input);
        $errors = [];

        $perPageOptions = $this->perPageOptions();
        $levels = $this->levels();
        $maxSearch = $this->settings->int('filters.search.max_length', 1, 1000);
        $presetKeys = array_merge([DatePresets::ALL, DatePresets::CUSTOM], $this->presets->keys());

        $validator = $this->validation->make($input, [
            'file' => ['nullable', 'string', 'regex:/^(all|[a-f0-9]{16})$/'],
            'levels' => ['nullable', 'array', 'max:'.count($levels)],
            'levels.*' => ['string', 'in:'.implode(',', $levels)],
            'channel' => ['nullable', 'string', 'max:100', 'regex:/^[\w.\-]+$/'],
            'q' => ['nullable', 'string', 'max:'.$maxSearch, 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
            'case' => ['nullable', 'boolean'],
            'regex' => ['nullable', 'boolean'],
            'traces' => ['nullable', 'boolean'],
            'preset' => ['nullable', 'string', 'in:'.implode(',', $presetKeys)],
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1970-01-02', 'before_or_equal:2100-01-01'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1970-01-02', 'before_or_equal:2100-01-01'],
            'sort' => ['nullable', 'string', 'in:'.implode(',', self::SORTS)],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'in:'.implode(',', $perPageOptions)],
        ], [], $this->attributeNames());

        foreach ($validator->errors()->messages() as $key => $messages) {
            $field = explode('.', $key)[0];
            $errors[$field] ??= $messages[0];
            unset($input[$field]);
        }

        $search = trim((string) ($input['q'] ?? ''));
        $regex = $search !== '' && $this->settings->bool('filters.search.regex') && $this->truthy($input['regex'] ?? null);

        if ($regex && SearchMatcher::compile($search, false) === null) {
            $errors['q'] = $this->translator->get('inertiapixel-log-viewer::log-viewer.errors.invalid_regex');
            $search = '';
            $regex = false;
        }

        $file = $this->file($input['file'] ?? null, $errors);
        [$preset, $fromDate, $toDate, $from, $to] = $this->dateRange($input, $errors);

        $filters = new LogFilters(
            file: $file,
            levels: $this->settings->bool('filters.levels.enabled') ? array_values(array_unique(array_map('strval', (array) ($input['levels'] ?? [])))) : [],
            channel: $this->settings->bool('filters.channels.enabled') && isset($input['channel']) ? (string) $input['channel'] : null,
            search: $this->settings->bool('filters.search.enabled') ? $search : '',
            caseSensitive: $this->settings->bool('filters.search.case_sensitive_toggle') && $this->truthy($input['case'] ?? null),
            regex: $regex,
            searchTraces: $this->settings->bool('filters.search.stack_trace_toggle') && $this->truthy($input['traces'] ?? null),
            preset: $preset,
            fromDate: $fromDate,
            toDate: $toDate,
            from: $from,
            to: $to,
            sort: (string) ($input['sort'] ?? $this->defaultSort()),
            page: (int) ($input['page'] ?? 1),
            perPage: (int) ($input['per_page'] ?? $this->defaultPerPage()),
            defaults: [
                'sort' => $this->defaultSort(),
                'per_page' => $this->defaultPerPage(),
                'preset' => $this->defaultPreset(),
            ],
        );

        return [$filters, $errors];
    }

    /**
     * Levels that can be filtered on.
     *
     * @return list<string>
     */
    public function levels(): array
    {
        $available = array_values(array_intersect(Level::all(), $this->settings->strings('filters.levels.available')));

        return array_merge($available === [] ? Level::all() : $available, [Level::UNKNOWN]);
    }

    /**
     * @return list<int>
     */
    public function perPageOptions(): array
    {
        $options = array_values(array_unique(array_filter(
            array_map('intval', $this->settings->array('ui.per_page_options')),
            fn (int $value) => $value >= 1 && $value <= 500,
        )));
        sort($options);

        return $options === [] ? [50] : $options;
    }

    public function defaultPerPage(): int
    {
        $options = $this->perPageOptions();
        $default = $this->settings->int('ui.per_page');

        return in_array($default, $options, true) ? $default : $options[0];
    }

    public function defaultSort(): string
    {
        $sort = $this->settings->string('ui.default_sort', 'newest');

        return in_array($sort, self::SORTS, true) ? $sort : 'newest';
    }

    public function defaultPreset(): string
    {
        $default = $this->settings->get('filters.date_range.default');

        return is_string($default) && $this->presets->has($default) ? $default : DatePresets::ALL;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function prepare(array $input): array
    {
        $input = array_intersect_key($input, array_flip([
            'file', 'levels', 'channel', 'q', 'case', 'regex', 'traces', 'preset', 'from', 'to', 'sort', 'page', 'per_page',
        ]));

        // Accept "levels=error,warning" as well as "levels[]=error".
        if (isset($input['levels']) && is_string($input['levels'])) {
            $input['levels'] = array_values(array_filter(explode(',', $input['levels'])));
        }

        foreach ($input as $key => $value) {
            if ($value === '' || $value === null) {
                unset($input[$key]);
            }
        }

        return $input;
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function file(mixed $value, array &$errors): ?string
    {
        if (! $this->settings->bool('filters.files.enabled')) {
            return null;
        }

        if ($value === null) {
            if ($this->settings->string('filters.files.default', 'all') === 'latest') {
                $first = array_key_first($this->sources->all());

                return is_string($first) ? $first : null;
            }

            return null;
        }

        if ($value === 'all') {
            return null;
        }

        if ($this->sources->find((string) $value) === null) {
            $errors['file'] = $this->translator->get('inertiapixel-log-viewer::log-viewer.errors.file_unavailable');

            return null;
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $errors
     * @return array{0: string, 1: ?string, 2: ?string, 3: ?int, 4: ?int}
     */
    private function dateRange(array $input, array &$errors): array
    {
        if (! $this->settings->bool('filters.date_range.enabled')) {
            return [DatePresets::ALL, null, null, null, null];
        }

        $fromDate = isset($input['from']) ? (string) $input['from'] : null;
        $toDate = isset($input['to']) ? (string) $input['to'] : null;
        $preset = isset($input['preset']) ? (string) $input['preset'] : null;

        // A named preset wins. Dates apply when the preset is "custom" or
        // absent, so the form also works without JavaScript (the preset
        // select is always submitted alongside the date inputs).
        if ($preset !== null && $preset !== DatePresets::CUSTOM) {
            $fromDate = $toDate = null;
        }

        if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
            $errors['to'] = $this->translator->get('inertiapixel-log-viewer::log-viewer.errors.range_order');
            $toDate = null;
        }

        if ($fromDate !== null || $toDate !== null) {
            [$from, $to] = $this->presets->dates($fromDate, $toDate);

            return [DatePresets::CUSTOM, $fromDate, $toDate, $from, $to];
        }

        $preset ??= $this->defaultPreset();

        if ($preset === DatePresets::CUSTOM || $preset === DatePresets::ALL) {
            return [DatePresets::ALL, null, null, null, null];
        }

        [$from, $to] = $this->presets->resolve($preset) ?? [null, null];

        return [$preset, null, null, $from, $to];
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on'], true);
    }

    /**
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        $names = [];

        foreach (['file', 'levels', 'channel', 'q', 'preset', 'from', 'to', 'sort', 'page', 'per_page'] as $field) {
            $names[$field] = $this->translator->get('inertiapixel-log-viewer::log-viewer.fields.'.$field);
        }

        $names['levels.*'] = $names['levels'];

        return $names;
    }
}
