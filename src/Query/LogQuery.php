<?php

namespace InertiaPixel\LaravelLogViewer\Query;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Contracts\Translation\Translator;
use InertiaPixel\LaravelLogViewer\Exceptions\UnreadableLogFileException;
use InertiaPixel\LaravelLogViewer\Indexing\LogIndex;
use InertiaPixel\LaravelLogViewer\Indexing\LogIndexer;
use InertiaPixel\LaravelLogViewer\Reading\LogReader;
use InertiaPixel\LaravelLogViewer\Sources\LogFile;
use InertiaPixel\LaravelLogViewer\Sources\LogSourceRepository;
use InertiaPixel\LaravelLogViewer\Support\Level;
use InertiaPixel\LaravelLogViewer\Support\Redactor;
use InertiaPixel\LaravelLogViewer\Support\Settings;

/**
 * Runs filters against the indexed files.
 *
 * Level, channel and date filters are evaluated on the index alone; only
 * a text search reads entry text, and only the entries on the requested
 * page are parsed. Every step is bounded by the scan window, the number
 * of files per query and the maximum entry size.
 */
final class LogQuery
{
    /** Upper bound of distinct messages tracked for "most frequent messages". */
    private const MAX_DISTINCT_MESSAGES = 20000;

    private const MAX_TREND_BUCKETS = 31;

    public function __construct(
        private readonly LogSourceRepository $sources,
        private readonly LogIndexer $indexer,
        private readonly LogReader $reader,
        private readonly EntryFactory $entries,
        private readonly Redactor $redactor,
        private readonly Settings $settings,
        private readonly DateTimeZone $timezone,
        private readonly Translator $translator,
    ) {
    }

    public function run(LogFilters $filters): QueryResult
    {
        $notices = [];
        $files = $this->files($filters, $notices);
        $matcher = $filters->hasSearch() ? new SearchMatcher($filters, $this->redactor) : null;
        $maxEntryBytes = $this->settings->int('limits.max_entry_bytes', 1024);
        $levelCodes = $filters->levels === [] ? null : array_flip(array_map(Level::code(...), $filters->levels));

        $sets = [];
        $levels = array_fill_keys(array_keys(Level::CODES), 0);
        $fileCounts = [];
        $channels = [];
        $messages = [];
        $partial = [];
        $available = [];
        $deadline = microtime(true) + $this->settings->int('limits.search_seconds', 1, 300);
        $timedOut = false;

        foreach ($files as $file) {
            if ($timedOut) {
                break;
            }

            try {
                // Indexing runs before the matcher lowers PCRE limits (custom
                // parsing patterns must not fail under the search limit).
                $index = $this->indexer->index($file);
                $handle = $matcher === null ? null : $this->reader->open($file);
            } catch (UnreadableLogFileException $exception) {
                $notices[] = $exception->getMessage();

                continue;
            }

            if ($index->partial) {
                $partial[] = $file;
            }

            array_push($available, ...$index->channels);

            $channelCode = null;

            if ($filters->channel !== null) {
                $position = array_search($filters->channel, $index->channels, true);
                $channelCode = $position === false ? -1 : $position + 1;
            }

            $rows = [];
            $levelCount = array_fill(0, 9, 0);
            $channelCount = [];
            $ordered = true;
            $previousTime = 0;

            $matcher?->begin();

            try {
                for ($i = 0, $count = $index->count(); $i < $count; $i++) {
                    $level = $index->level($i);

                    if ($levelCodes !== null && ! isset($levelCodes[$level])) {
                        continue;
                    }

                    if ($channelCode !== null && $index->channel($i) !== $channelCode) {
                        continue;
                    }

                    if ($filters->from !== null || $filters->to !== null) {
                        $time = $index->time($i);

                        if ($time === 0 || ($filters->from !== null && $time < $filters->from) || ($filters->to !== null && $time > $filters->to)) {
                            continue;
                        }
                    }

                    if ($handle !== null) {
                        if (($i & 255) === 0 && microtime(true) > $deadline) {
                            $timedOut = true;

                            break;
                        }

                        [$raw] = $this->reader->read($handle, $index->offset($i), $index->length($i), $maxEntryBytes);

                        if (! $matcher->matches($raw)) {
                            continue;
                        }
                    }

                    $rows[] = $i;
                    $time = $index->time($i);
                    $ordered = $ordered && $time >= $previousTime;
                    $previousTime = $time;
                    $levelCount[$level]++;
                    $channelCount[$index->channel($i)] = ($channelCount[$index->channel($i)] ?? 0) + 1;

                    $hash = $index->hash($i);

                    if (isset($messages[$hash])) {
                        $messages[$hash][0]++;
                        $messages[$hash][1] = count($sets);
                        $messages[$hash][2] = $i;
                    } elseif (count($messages) < self::MAX_DISTINCT_MESSAGES) {
                        $messages[$hash] = [1, count($sets), $i];
                    }
                }
            } finally {
                $matcher?->end();

                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            // Files are normally chronological; sort only when they are not
            // (clock changes, merged or hand-edited files). Ties keep file order.
            if (! $ordered) {
                $times = array_map($index->time(...), $rows);
                array_multisort($times, SORT_ASC, SORT_NUMERIC, $rows, SORT_ASC, SORT_NUMERIC);
            }

            foreach ($levelCount as $code => $value) {
                $levels[Level::name($code)] += $value;
            }

            foreach ($channelCount as $code => $value) {
                $name = $index->channelName($code);

                if ($name !== null) {
                    $channels[$name] = ($channels[$name] ?? 0) + $value;
                }
            }

            $fileCounts[$file->id] = count($rows);
            $sets[] = ['file' => $file, 'index' => $index, 'rows' => $rows];
        }

        if ($timedOut) {
            $notices[] = $this->translator->get('inertiapixel-log-viewer::log-viewer.notices.search_timeout', ['seconds' => $this->settings->int('limits.search_seconds', 1, 300)]);
        }

        if ($matcher?->failed) {
            $notices[] = $this->translator->get('inertiapixel-log-viewer::log-viewer.notices.regex_limit');
        }

        foreach ($partial as $file) {
            $notices[] = $this->translator->get('inertiapixel-log-viewer::log-viewer.notices.partial', [
                'file' => $file->name,
                'size' => self::bytes($this->settings->int('limits.scan_bytes', 1024)),
            ]);
        }

        arsort($channels);
        $total = array_sum($fileCounts);
        $lastPage = max(1, (int) ceil($total / $filters->perPage));
        $page = min($filters->page, $lastPage);

        return new QueryResult(
            entries: $this->page($sets, $filters->sort, ($page - 1) * $filters->perPage, $filters->perPage, $total),
            total: $total,
            page: $page,
            perPage: $filters->perPage,
            levels: $levels,
            files: $fileCounts,
            channels: $channels,
            trend: $this->trend($sets, $filters),
            topMessages: $this->topMessages($sets, $messages),
            scannedFiles: array_map(fn (array $set) => $set['file'], $sets),
            partial: $partial !== [],
            notices: array_values(array_unique($notices)),
            availableChannels: $this->sortedUnique($available),
        );
    }

    /**
     * Full detail of one entry, located by file, byte offset and content
     * hash. Returns null when the entry no longer exists at that position
     * (rotated, truncated or rewritten file, or outside the scan window).
     */
    public function find(string $fileId, int $offset, string $hash): ?LogEntry
    {
        $file = $this->sources->find($fileId);

        if ($file === null || ! $file->readable) {
            return null;
        }

        $index = $this->indexer->index($file);
        $row = $index->find($offset);

        if ($row === null) {
            return null;
        }

        $handle = $this->reader->open($file);

        try {
            [$raw, $truncated] = $this->reader->read($handle, $offset, $index->length($row), $this->settings->int('limits.max_entry_bytes', 1024));
        } finally {
            fclose($handle);
        }

        if (! hash_equals($this->entries->fingerprint($raw), $hash)) {
            return null;
        }

        return $this->entries->detail($file, $index, $row, $raw, $truncated);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sortedUnique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_NATURAL | SORT_FLAG_CASE);

        return $values;
    }

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;
        $value = $bytes / (1024 ** $power);

        return ($power === 0 ? (string) $bytes : rtrim(rtrim(number_format($value, 1), '0'), '.')).' '.$units[$power];
    }

    /**
     * Files to search: the selected one, or the most recently modified
     * readable files (skipping files untouched since before the range).
     *
     * @param  list<string>  $notices
     * @return list<LogFile>
     */
    private function files(LogFilters $filters, array &$notices): array
    {
        $all = $this->sources->all();

        if ($filters->file !== null) {
            $file = $all[$filters->file] ?? null;

            if ($file !== null && ! $file->readable) {
                $notices[] = $this->translator->get('inertiapixel-log-viewer::log-viewer.notices.unreadable', ['file' => $file->name]);

                return [];
            }

            return $file === null ? [] : [$file];
        }

        $limit = $this->settings->int('limits.max_files_per_query', 1, 1000);
        $selected = [];
        $skipped = 0;

        foreach ($all as $file) {
            if (! $file->readable) {
                $notices[] = $this->translator->get('inertiapixel-log-viewer::log-viewer.notices.unreadable', ['file' => $file->name]);

                continue;
            }

            // Entries are appended, so a file last modified (with a day of
            // slack for clock and timezone differences) before the range
            // starts cannot contain matching entries.
            if ($filters->from !== null && $file->modifiedAt < $filters->from - 86400) {
                continue;
            }

            if (count($selected) >= $limit) {
                $skipped++;

                continue;
            }

            $selected[] = $file;
        }

        if ($skipped > 0) {
            $notices[] = $this->translator->get('inertiapixel-log-viewer::log-viewer.notices.file_limit', ['count' => $limit, 'skipped' => $skipped]);
        }

        return $selected;
    }

    /**
     * Merge the per-file matches (each sorted by time) by timestamp and
     * return one page. Skipped entries are counted, not stored, and pages
     * past the middle are walked from the other end, so memory is constant
     * and time is bounded by half the matches.
     *
     * @param  list<array{file: LogFile, index: LogIndex, rows: list<int>}>  $sets
     * @return list<LogEntry>
     */
    private function page(array $sets, string $sort, int $skip, int $take, int $total): array
    {
        $take = max(0, min($take, $total - $skip));

        if ($take === 0) {
            return [];
        }

        $newest = $sort !== 'oldest';
        $reverse = $skip > $total - $skip - $take;

        if ($reverse) {
            [$newest, $skip] = [! $newest, $total - $skip - $take];
        }

        $nonEmpty = array_keys(array_filter($sets, fn (array $set) => $set['rows'] !== []));
        $picked = [];

        if (count($nonEmpty) === 1) {
            $key = $nonEmpty[0];
            $rows = $sets[$key]['rows'];
            $count = count($rows);

            for ($n = $skip; $n < $skip + $take; $n++) {
                $picked[] = [$key, $rows[$newest ? $count - 1 - $n : $n]];
            }
        } else {
            $cursors = [];

            foreach ($nonEmpty as $key) {
                $cursors[$key] = $newest ? count($sets[$key]['rows']) - 1 : 0;
            }

            for ($n = 0; $cursors !== [] && $n < $skip + $take; $n++) {
                $best = null;
                $bestTime = 0;

                foreach ($cursors as $key => $position) {
                    $time = $sets[$key]['index']->time($sets[$key]['rows'][$position]);
                    // Ties go to the earlier file, or to the later one when walking
                    // backwards, so both directions produce the same order.
                    $better = $newest ? $time > $bestTime : $time < $bestTime;

                    if ($best === null || $better || ($reverse && $time === $bestTime)) {
                        [$best, $bestTime] = [$key, $time];
                    }
                }

                if ($n >= $skip) {
                    $picked[] = [$best, $sets[$best]['rows'][$cursors[$best]]];
                }

                $cursors[$best] += $newest ? -1 : 1;

                if ($cursors[$best] < 0 || $cursors[$best] >= count($sets[$best]['rows'])) {
                    unset($cursors[$best]);
                }
            }
        }

        return $this->read($sets, $reverse ? array_reverse($picked) : $picked);
    }

    /**
     * @param  list<array{file: LogFile, index: LogIndex, rows: list<int>}>  $sets
     * @param  list<array{0: int, 1: int}>  $picked
     * @return list<LogEntry>
     */
    private function read(array $sets, array $picked): array
    {
        $maxEntryBytes = $this->settings->int('limits.max_entry_bytes', 1024);
        $handles = [];
        $entries = [];

        try {
            foreach ($picked as [$key, $row]) {
                ['file' => $file, 'index' => $index] = $sets[$key];

                try {
                    $handles[$key] ??= $this->reader->open($file);
                } catch (UnreadableLogFileException) {
                    continue;
                }

                [$raw, $truncated] = $this->reader->read($handles[$key], $index->offset($row), $index->length($row), $maxEntryBytes);
                $entries[] = $this->entries->summary($file, $index, $row, $raw, $truncated);
            }
        } finally {
            foreach ($handles as $handle) {
                fclose($handle);
            }
        }

        return $entries;
    }

    /**
     * Entry counts over time, grouped into errors, warnings and other.
     * The bucket size adapts to the range (hours up to months).
     *
     * @param  list<array{file: LogFile, index: LogIndex, rows: list<int>}>  $sets
     * @return array{interval: string, buckets: list<array{label: string, start: int, errors: int, warnings: int, other: int}>}|null
     */
    private function trend(array $sets, LogFilters $filters): ?array
    {
        [$min, $max] = [PHP_INT_MAX, 0];

        foreach ($sets as $set) {
            foreach ([reset($set['rows']), end($set['rows'])] as $row) {
                if ($row !== false && ($time = $set['index']->time($row)) > 0) {
                    [$min, $max] = [min($min, $time), max($max, $time)];
                }
            }
        }

        if ($max === 0) {
            return null;
        }

        $now = time();
        $from = $filters->from ?? $min;
        $to = $filters->to === null ? $max : max($max, min($filters->to, $now));
        [$interval, $boundaries] = $this->buckets($from, max($from, $to));
        $count = count($boundaries);
        $buckets = [];

        foreach ($boundaries as $start) {
            $buckets[] = ['label' => $this->bucketLabel($start, $interval), 'start' => $start, 'errors' => 0, 'warnings' => 0, 'other' => 0];
        }

        $warning = Level::code('warning');

        foreach ($sets as $set) {
            $index = $set['index'];

            foreach ($set['rows'] as $row) {
                $time = $index->time($row);

                if ($time < $boundaries[0]) {
                    continue;
                }

                // Binary search for the last boundary <= $time.
                [$low, $high] = [0, $count - 1];

                while ($low < $high) {
                    $middle = intdiv($low + $high + 1, 2);
                    $boundaries[$middle] <= $time ? $low = $middle : $high = $middle - 1;
                }

                $level = $index->level($row);
                $group = $level > $warning ? 'errors' : ($level === $warning ? 'warnings' : 'other');
                $buckets[$low][$group]++;
            }
        }

        return ['interval' => $interval, 'buckets' => $buckets];
    }

    /**
     * Calendar-aligned bucket start times in the display timezone.
     *
     * @return array{0: string, 1: list<int>}
     */
    private function buckets(int $from, int $to): array
    {
        $span = $to - $from;
        $steps = [
            ['hour', 1], ['hour', 3], ['hour', 6],
            ['day', 1], ['week', 1], ['month', 1], ['month', 3], ['year', 1],
        ];
        $approx = ['hour' => 3600, 'day' => 86400, 'week' => 604800, 'month' => 2629800, 'year' => 31557600];

        foreach ($steps as [$unit, $size]) {
            if ($span / ($approx[$unit] * $size) <= self::MAX_TREND_BUCKETS) {
                break;
            }
        }

        $cursor = CarbonImmutable::createFromTimestamp($from, $this->timezone);
        $cursor = match ($unit) {
            'hour' => $cursor->startOfHour()->setTime(intdiv($cursor->hour, $size) * $size, 0),
            'day' => $cursor->startOfDay(),
            'week' => $cursor->startOfWeek($this->settings->int('filters.date_range.first_weekday', 0, 6)),
            'month' => $cursor->startOfMonth()->setDate($cursor->year, intdiv($cursor->month - 1, $size) * $size + 1, 1),
            default => $cursor->startOfYear(),
        };

        $boundaries = [];

        while ($cursor->getTimestamp() <= $to && count($boundaries) < self::MAX_TREND_BUCKETS * 2) {
            $boundaries[] = $cursor->getTimestamp();
            $cursor = $cursor->add($unit, $size);
        }

        return [$size === 1 ? $unit : $size.' '.$unit.'s', $boundaries];
    }

    private function bucketLabel(int $start, string $interval): string
    {
        $date = CarbonImmutable::createFromTimestamp($start, $this->timezone)->locale((string) $this->translator->getLocale());

        return match (true) {
            str_contains($interval, 'hour') => $date->isoFormat('MMM D, HH:mm'),
            str_contains($interval, 'month'), str_contains($interval, 'year') => $date->isoFormat('MMM YYYY'),
            default => $date->isoFormat('MMM D'),
        };
    }

    /**
     * @param  list<array{file: LogFile, index: LogIndex, rows: list<int>}>  $sets
     * @param  array<int, array{0: int, 1: int, 2: int}>  $messages  hash => [count, set, row of the latest occurrence]
     * @return list<array{message: string, count: int, level: string}>
     */
    private function topMessages(array $sets, array $messages): array
    {
        $limit = $this->settings->int('limits.top_messages', 0, 50);

        if ($limit === 0 || $messages === []) {
            return [];
        }

        uasort($messages, fn (array $a, array $b) => $b[0] <=> $a[0]);
        $top = [];

        foreach (array_slice($messages, 0, $limit, true) as [$count, $key, $row]) {
            if ($count < 2) {
                break;
            }

            $entry = $this->read($sets, [[$key, $row]])[0] ?? null;

            if ($entry !== null) {
                $top[] = ['message' => $entry->summary, 'count' => $count, 'level' => $entry->level];
            }
        }

        return $top;
    }
}
