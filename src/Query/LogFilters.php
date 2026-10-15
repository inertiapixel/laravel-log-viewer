<?php

namespace InertiaPixel\LaravelLogViewer\Query;

/**
 * Validated, normalized filters for one query. Built by FilterFactory;
 * every value here is safe to use.
 */
final class LogFilters
{
    /**
     * @param  list<string>  $levels
     * @param  array<string, mixed>  $defaults  Parameter values omitted from URLs.
     */
    public function __construct(
        public readonly ?string $file = null,
        public readonly array $levels = [],
        public readonly ?string $channel = null,
        public readonly string $search = '',
        public readonly bool $caseSensitive = false,
        public readonly bool $regex = false,
        public readonly bool $searchTraces = false,
        public readonly string $preset = DatePresets::ALL,
        public readonly ?string $fromDate = null,
        public readonly ?string $toDate = null,
        public readonly ?int $from = null,
        public readonly ?int $to = null,
        public readonly string $sort = 'newest',
        public readonly int $page = 1,
        public readonly int $perPage = 50,
        private readonly array $defaults = [],
    ) {
    }

    /**
     * Query-string parameters reproducing these filters. Only filter values
     * are included (nothing server-side), so URLs are safe to share.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function query(array $overrides = []): array
    {
        $query = [
            'file' => $this->file,
            'levels' => $this->levels === [] ? null : $this->levels,
            'channel' => $this->channel,
            'q' => $this->search === '' ? null : $this->search,
            'case' => $this->search !== '' && $this->caseSensitive ? 1 : null,
            'regex' => $this->search !== '' && $this->regex ? 1 : null,
            'traces' => $this->search !== '' && $this->searchTraces ? 1 : null,
            'preset' => $this->preset,
            'from' => $this->preset === DatePresets::CUSTOM ? $this->fromDate : null,
            'to' => $this->preset === DatePresets::CUSTOM ? $this->toDate : null,
            'sort' => $this->sort,
            'per_page' => $this->perPage,
            'page' => $this->page > 1 ? $this->page : null,
        ];

        $query = array_merge($query, $overrides);

        foreach ($this->defaults as $key => $default) {
            if (isset($query[$key]) && is_scalar($query[$key]) && (string) $query[$key] === (string) $default) {
                unset($query[$key]);
            }
        }

        return array_filter($query, fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    public function hasSearch(): bool
    {
        return $this->search !== '';
    }
}
