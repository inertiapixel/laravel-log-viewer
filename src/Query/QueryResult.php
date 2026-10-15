<?php

namespace InertiaPixel\LaravelLogViewer\Query;

use InertiaPixel\LaravelLogViewer\Sources\LogFile;
use InertiaPixel\LaravelLogViewer\Support\Level;

/**
 * The outcome of one query: the current page of entries plus statistics
 * over every matching entry within the scanned window.
 */
final class QueryResult
{
    /**
     * @param  list<LogEntry>  $entries
     * @param  array<string, int>  $levels  level => count (every level present, zero included)
     * @param  array<string, int>  $files  file id => count
     * @param  array<string, int>  $channels  channel => count
     * @param  array{interval: string, buckets: list<array{label: string, start: int, errors: int, warnings: int, other: int}>}|null  $trend
     * @param  list<array{message: string, count: int, level: string}>  $topMessages
     * @param  list<LogFile>  $scannedFiles
     * @param  list<string>  $notices
     * @param  list<string>  $availableChannels  every channel seen in the scanned files
     */
    public function __construct(
        public readonly array $entries,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly array $levels,
        public readonly array $files,
        public readonly array $channels,
        public readonly ?array $trend,
        public readonly array $topMessages,
        public readonly array $scannedFiles,
        public readonly bool $partial,
        public readonly array $notices,
        public readonly array $availableChannels = [],
    ) {
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function firstItem(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function lastItem(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }

    /** Emergency, alert, critical and error entries. */
    public function errorCount(): int
    {
        $count = 0;

        foreach ($this->levels as $level => $value) {
            $count += Level::isError($level) ? $value : 0;
        }

        return $count;
    }
}
