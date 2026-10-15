<?php

namespace InertiaPixel\LaravelLogViewer\Indexing;

/**
 * Compact, column-oriented index of the entries in one log file.
 *
 * Each column is a binary string with a fixed width per row (27 bytes per
 * entry in total), so tens of thousands of entries cost a few hundred
 * kilobytes instead of megabytes of PHP arrays. It holds byte offsets,
 * timestamps, levels and message hashes, never log text.
 */
final class LogIndex
{
    public const FLAG_OWN_TIME = 1;

    public const FLAG_TRACE = 2;

    public const FLAG_UNPARSED = 4;

    /** Channel code for entries without a channel. */
    public const NO_CHANNEL = 0;

    /** Channel code shared by channels beyond the 254 distinct names stored. */
    public const OTHER_CHANNEL = 255;

    private const VERSION = 1;

    public string $offsets = '';

    public string $lengths = '';

    public string $times = '';

    public string $levels = '';

    public string $channelCodes = '';

    public string $flags = '';

    public string $hashes = '';

    /** @var list<string> channel name for code (index + 1) */
    public array $channels = [];

    /** Byte offset where indexing started (older content was not scanned). */
    public int $start = 0;

    /** File size covered by the index. */
    public int $end = 0;

    /** Whether older content exists that is not indexed. */
    public bool $partial = false;

    public int $inode = 0;

    /** Hash of the first bytes of the first entry, to detect rewritten files. */
    public string $anchor = '';

    public string $signature = '';

    public function count(): int
    {
        return strlen($this->levels);
    }

    public function add(int $offset, int $length, int $time, int $level, int $channel, int $flags, int $hash): void
    {
        $this->offsets .= pack('J', $offset);
        $this->lengths .= pack('N', min($length, 0xFFFFFFFF));
        $this->times .= pack('J', max(0, $time));
        $this->levels .= chr($level);
        $this->channelCodes .= chr($channel);
        $this->flags .= chr($flags);
        $this->hashes .= pack('N', $hash & 0xFFFFFFFF);
    }

    public function offset(int $i): int
    {
        return unpack('J', $this->offsets, $i * 8)[1];
    }

    public function length(int $i): int
    {
        return unpack('N', $this->lengths, $i * 4)[1];
    }

    /** Unix timestamp, or 0 when unknown. */
    public function time(int $i): int
    {
        return unpack('J', $this->times, $i * 8)[1];
    }

    public function level(int $i): int
    {
        return ord($this->levels[$i]);
    }

    public function channel(int $i): int
    {
        return ord($this->channelCodes[$i]);
    }

    public function flag(int $i, int $flag): bool
    {
        return (ord($this->flags[$i]) & $flag) === $flag;
    }

    public function hash(int $i): int
    {
        return unpack('N', $this->hashes, $i * 4)[1];
    }

    /** Remove the last row (it is re-parsed when the file grows). */
    public function pop(): void
    {
        $this->slice(0, $this->count() - 1);
    }

    /** Keep only the last $keep rows. */
    public function keepLast(int $keep): void
    {
        $count = $this->count();

        if ($count > $keep) {
            $this->slice($count - $keep, $keep);
            $this->partial = true;
        }
    }

    /** Drop rows that start before $offset. */
    public function dropBefore(int $offset): void
    {
        $count = $this->count();
        $first = 0;

        while ($first < $count && $this->offset($first) < $offset) {
            $first++;
        }

        if ($first > 0) {
            $this->slice($first, $count - $first);
            $this->partial = true;
        }
    }

    /** Row index of the entry starting exactly at $offset, by binary search. */
    public function find(int $offset): ?int
    {
        $low = 0;
        $high = $this->count() - 1;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $value = $this->offset($middle);

            if ($value === $offset) {
                return $middle;
            }

            if ($value < $offset) {
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return null;
    }

    public function channelCode(?string $name): int
    {
        if ($name === null || $name === '') {
            return self::NO_CHANNEL;
        }

        $index = array_search($name, $this->channels, true);

        if ($index !== false) {
            return $index + 1;
        }

        if (count($this->channels) >= 254) {
            return self::OTHER_CHANNEL;
        }

        $this->channels[] = $name;

        return count($this->channels);
    }

    public function channelName(int $code): ?string
    {
        return $this->channels[$code - 1] ?? null;
    }

    public function firstOffset(): int
    {
        return $this->count() > 0 ? $this->offset(0) : $this->start;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'v' => self::VERSION,
            'columns' => array_map('base64_encode', [
                $this->offsets, $this->lengths, $this->times, $this->levels, $this->channelCodes, $this->flags, $this->hashes,
            ]),
            'channels' => $this->channels,
            'start' => $this->start,
            'end' => $this->end,
            'partial' => $this->partial,
            'inode' => $this->inode,
            'anchor' => $this->anchor,
            'signature' => $this->signature,
        ];
    }

    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ($data['v'] ?? null) !== self::VERSION || ! is_array($data['columns'] ?? null) || count($data['columns']) !== 7) {
            return null;
        }

        $columns = array_map(fn ($column) => is_string($column) ? base64_decode($column, true) : false, $data['columns']);

        if (in_array(false, $columns, true)) {
            return null;
        }

        $index = new self();
        [$index->offsets, $index->lengths, $index->times, $index->levels, $index->channelCodes, $index->flags, $index->hashes] = $columns;
        $count = strlen($index->levels);

        if (strlen($index->offsets) !== $count * 8 || strlen($index->lengths) !== $count * 4 || strlen($index->times) !== $count * 8
            || strlen($index->channelCodes) !== $count || strlen($index->flags) !== $count || strlen($index->hashes) !== $count * 4) {
            return null;
        }

        $index->channels = array_values(array_filter((array) ($data['channels'] ?? []), 'is_string'));
        $index->start = (int) ($data['start'] ?? 0);
        $index->end = (int) ($data['end'] ?? 0);
        $index->partial = (bool) ($data['partial'] ?? false);
        $index->inode = (int) ($data['inode'] ?? 0);
        $index->anchor = (string) ($data['anchor'] ?? '');
        $index->signature = (string) ($data['signature'] ?? '');

        return $index;
    }

    private function slice(int $from, int $count): void
    {
        $count = max(0, $count);
        $this->offsets = substr($this->offsets, $from * 8, $count * 8);
        $this->lengths = substr($this->lengths, $from * 4, $count * 4);
        $this->times = substr($this->times, $from * 8, $count * 8);
        $this->levels = substr($this->levels, $from, $count);
        $this->channelCodes = substr($this->channelCodes, $from, $count);
        $this->flags = substr($this->flags, $from, $count);
        $this->hashes = substr($this->hashes, $from * 4, $count * 4);
    }
}
