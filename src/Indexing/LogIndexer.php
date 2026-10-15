<?php

namespace InertiaPixel\LaravelLogViewer\Indexing;

use Illuminate\Contracts\Cache\Repository as Cache;
use InertiaPixel\LaravelLogViewer\Parsing\LogParser;
use InertiaPixel\LaravelLogViewer\Reading\LogReader;
use InertiaPixel\LaravelLogViewer\Sources\LogFile;
use InertiaPixel\LaravelLogViewer\Support\Level;
use Throwable;

/**
 * Builds and maintains the entry index of each log file.
 *
 * Only the newest "scan_bytes" of a file are indexed. A cached index is
 * reused while the file is unchanged and extended incrementally when it
 * grows (the last entry is re-parsed, since it may have been incomplete).
 * A different inode, a smaller size or different leading bytes (rotation,
 * truncation, rewrite) trigger a rebuild.
 */
final class LogIndexer
{
    private const ANCHOR_BYTES = 64;

    /** @var array<string, LogIndex> */
    private array $memory = [];

    public function __construct(
        private readonly LogReader $reader,
        private readonly LogParser $parser,
        private readonly ?Cache $cache,
        private readonly int $ttl,
        private readonly string $prefix,
        private readonly int $scanBytes,
        private readonly int $maxEntries,
        private readonly string $signature,
    ) {
    }

    public function index(LogFile $file): LogIndex
    {
        $handle = $this->reader->open($file);

        try {
            ['size' => $size, 'inode' => $inode] = $this->reader->stat($handle);
            $cached = $this->memory[$file->id] ?? $this->load($file);

            if ($cached !== null && $this->reusable($cached, $handle, $size, $inode)) {
                if ($cached->end === $size) {
                    return $this->memory[$file->id] = $cached;
                }

                $index = $this->extend($cached, $handle, $size);
            } else {
                $index = $this->build($handle, $size, $inode);
            }

            $this->store($file, $index);

            return $this->memory[$file->id] = $index;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function build($handle, int $size, int $inode): LogIndex
    {
        $index = new LogIndex();
        $index->inode = $inode;
        $index->signature = $this->signature;
        $index->start = max(0, $size - $this->scanBytes);
        $index->partial = $index->start > 0;

        $this->scan($index, $handle, $index->start, $size, false, 0);
        $this->finish($index, $handle, $size);

        return $index;
    }

    /**
     * @param  resource  $handle
     */
    private function extend(LogIndex $index, $handle, int $size): LogIndex
    {
        $count = $index->count();

        if ($count === 0) {
            if ($size - $index->end > $this->scanBytes) {
                return $this->build($handle, $size, $index->inode);
            }

            $this->scan($index, $handle, $index->end, $size, false, 0);
        } else {
            $resumeAt = $index->offset($count - 1);

            // More new data than the scan window: a fresh build reads less.
            if ($size - $resumeAt > $this->scanBytes) {
                return $this->build($handle, $size, $index->inode);
            }

            $lastTime = $count > 1 ? $index->time($count - 2) : 0;
            $index->pop();
            $this->scan($index, $handle, $resumeAt, $size, true, $lastTime);
        }

        $index->dropBefore($size - $this->scanBytes);
        $this->finish($index, $handle, $size);

        return $index;
    }

    /**
     * Scan lines in [$from, $to) and append index rows.
     *
     * Lines before the first recognized header ("orphans") become separate
     * unparsed entries when the scan starts at a known boundary (file start
     * or a resumed entry). When it starts mid-file they are the tail of an
     * entry that is not indexed and are dropped, unless the window holds no
     * header at all (plain-text logs), in which case every line is an entry.
     *
     * @param  resource  $handle
     */
    private function scan(LogIndex $index, $handle, int $from, int $to, bool $resume, int $lastTime): void
    {
        $keepOrphans = $resume || $from === 0;
        $seenHeader = false;
        $orphans = new LogIndex();
        $pending = null;
        $current = null;
        $trim = $this->maxEntries * 2;

        foreach ($this->reader->lines($handle, $from, $to, ! $resume) as $offset => $line) {
            $header = $this->parser->header($line);

            if ($header !== null) {
                if ($current !== null) {
                    $this->emit($index, $current, $offset - $current['offset']);
                } elseif (! $seenHeader) {
                    if ($pending !== null) {
                        $this->emit($orphans, $pending, $offset - $pending['offset']);
                        $pending = null;
                    }

                    if ($keepOrphans) {
                        $this->append($index, $orphans);
                    } elseif ($orphans->count() > 0) {
                        $index->partial = true;
                    }

                    $orphans = new LogIndex();
                }

                $seenHeader = true;
                $own = $header['timestamp'] !== null;
                $lastTime = $own ? (int) $header['timestamp'] : $lastTime;

                $current = [
                    'offset' => $offset,
                    'time' => $lastTime,
                    'level' => Level::code($header['level']),
                    'channel' => $index->channelCode($header['channel']),
                    'flags' => ($own ? LogIndex::FLAG_OWN_TIME : 0) | ($header['trace'] ? LogIndex::FLAG_TRACE : 0),
                    'hash' => LogParser::messageHash($header['message']),
                ];

                if ($index->count() > $trim) {
                    $index->keepLast($this->maxEntries);
                }

                continue;
            }

            if ($current !== null) {
                if (($current['flags'] & LogIndex::FLAG_TRACE) === 0 && LogParser::isTraceLine($line)) {
                    $current['flags'] |= LogIndex::FLAG_TRACE;
                }

                continue;
            }

            // Orphan line: each becomes its own unparsed entry.
            if ($pending !== null) {
                $this->emit($orphans, $pending, $offset - $pending['offset']);

                if ($orphans->count() > $trim) {
                    $orphans->keepLast($this->maxEntries);
                }
            }

            $pending = [
                'offset' => $offset,
                'time' => $lastTime,
                'level' => Level::code(Level::UNKNOWN),
                'channel' => LogIndex::NO_CHANNEL,
                'flags' => LogIndex::FLAG_UNPARSED,
                'hash' => LogParser::messageHash($line),
            ];
        }

        if ($current !== null) {
            $this->emit($index, $current, $to - $current['offset']);
        }

        if (! $seenHeader) {
            if ($pending !== null) {
                $this->emit($orphans, $pending, $to - $pending['offset']);
            }

            $this->append($index, $orphans);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function finish(LogIndex $index, $handle, int $size): void
    {
        $index->keepLast($this->maxEntries);
        $index->end = $size;
        $index->start = $index->firstOffset();
        $index->anchor = $this->anchor($handle, $index);
    }

    /**
     * @param  resource  $handle
     */
    private function reusable(LogIndex $index, $handle, int $size, int $inode): bool
    {
        return $index->signature === $this->signature
            && $index->inode === $inode
            && $size >= $index->end
            && $index->anchor === $this->anchor($handle, $index);
    }

    /**
     * @param  resource  $handle
     */
    private function anchor($handle, LogIndex $index): string
    {
        if ($index->count() === 0) {
            return '';
        }

        [$bytes] = $this->reader->read($handle, $index->offset(0), self::ANCHOR_BYTES, self::ANCHOR_BYTES);

        return md5($bytes);
    }

    /**
     * @param  array{offset: int, time: int, level: int, channel: int, flags: int, hash: int}  $row
     */
    private function emit(LogIndex $index, array $row, int $length): void
    {
        $index->add($row['offset'], max(0, $length), $row['time'], $row['level'], $row['channel'], $row['flags'], $row['hash']);
    }

    private function append(LogIndex $index, LogIndex $rows): void
    {
        for ($i = 0, $count = $rows->count(); $i < $count; $i++) {
            $index->add($rows->offset($i), $rows->length($i), $rows->time($i), $rows->level($i), $rows->channel($i), ord($rows->flags[$i]), $rows->hash($i));
        }
    }

    private function key(LogFile $file): string
    {
        // The index describes a local file, so the host name is part of the key
        // in case a shared cache store is configured.
        return $this->prefix.':index:'.sha1((gethostname() ?: 'host').'|'.$file->path);
    }

    private function load(LogFile $file): ?LogIndex
    {
        if ($this->cache === null) {
            return null;
        }

        try {
            return LogIndex::fromArray($this->cache->get($this->key($file)));
        } catch (Throwable) {
            return null;
        }
    }

    private function store(LogFile $file, LogIndex $index): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->put($this->key($file), $index->toArray(), $this->ttl);
        } catch (Throwable) {
            // The cache is an optimization only.
        }
    }
}
