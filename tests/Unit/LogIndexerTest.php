<?php

namespace InertiaPixel\LaravelLogViewer\Tests\Unit;

use DateTimeZone;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use InertiaPixel\LaravelLogViewer\Indexing\LogIndex;
use InertiaPixel\LaravelLogViewer\Indexing\LogIndexer;
use InertiaPixel\LaravelLogViewer\Parsing\LogParser;
use InertiaPixel\LaravelLogViewer\Reading\LogReader;
use InertiaPixel\LaravelLogViewer\Sources\LogFile;
use InertiaPixel\LaravelLogViewer\Support\Level;
use InertiaPixel\LaravelLogViewer\Tests\Concerns\CreatesLogFiles;
use PHPUnit\Framework\TestCase;

class LogIndexerTest extends TestCase
{
    use CreatesLogFiles;

    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new Repository(new ArrayStore());
    }

    protected function tearDown(): void
    {
        $this->removeLogDirectory();

        parent::tearDown();
    }

    private function indexer(int $scanBytes = 1048576, int $maxEntries = 100000, ?Repository $cache = null): LogIndexer
    {
        return new LogIndexer(
            new LogReader(),
            new LogParser(['laravel', 'json'], [], new DateTimeZone('UTC')),
            $cache ?? $this->cache,
            600,
            'test',
            $scanBytes,
            $maxEntries,
            'signature',
        );
    }

    private function file(string $path): LogFile
    {
        clearstatcache();

        return new LogFile(LogFile::idFor($path), basename($path), $path, 'logs', (int) filesize($path), (int) filemtime($path), true);
    }

    /** @return list<array{offset: int, length: int, level: string, text: string}> */
    private function rows(LogIndex $index, string $path): array
    {
        $contents = (string) file_get_contents($path);
        $rows = [];

        for ($i = 0; $i < $index->count(); $i++) {
            $rows[] = [
                'offset' => $index->offset($i),
                'length' => $index->length($i),
                'level' => Level::name($index->level($i)),
                'text' => substr($contents, $index->offset($i), $index->length($i)),
            ];
        }

        return $rows;
    }

    public function test_multiline_entries_and_flags(): void
    {
        $path = $this->writeLog('a.log', $this->fixture('laravel-real.log'));
        $index = $this->indexer()->index($this->file($path));
        $rows = $this->rows($index, $path);

        $this->assertSame(8, $index->count());
        $this->assertSame(filesize($path), array_sum(array_column($rows, 'length')));
        $this->assertSame('error', $rows[4]['level']);
        $this->assertStringContainsString('[previous exception]', $rows[4]['text']);
        $this->assertTrue($index->flag(4, LogIndex::FLAG_TRACE));
        $this->assertFalse($index->flag(0, LogIndex::FLAG_TRACE));
        $this->assertTrue($index->flag(0, LogIndex::FLAG_OWN_TIME));
        $this->assertSame(['production'], $index->channels);
        $this->assertFalse($index->partial);
    }

    public function test_orphan_lines_at_the_start_become_unparsed_entries(): void
    {
        $path = $this->writeLog('a.log', "garbage one\ngarbage two\n".$this->line('2026-10-15 10:00:00', 'info', 'hello'));
        $index = $this->indexer()->index($this->file($path));
        $rows = $this->rows($index, $path);

        $this->assertSame(['garbage one'."\n", 'garbage two'."\n"], [$rows[0]['text'], $rows[1]['text']]);
        $this->assertTrue($index->flag(0, LogIndex::FLAG_UNPARSED));
        $this->assertSame('unknown', $rows[0]['level']);
        $this->assertSame('info', $rows[2]['level']);
    }

    public function test_plain_text_files_index_every_line(): void
    {
        $path = $this->writeLog('plain.log', "one\ntwo\nthree");
        $index = $this->indexer()->index($this->file($path));

        $this->assertSame(["one\n", "two\n", 'three'], array_column($this->rows($index, $path), 'text'));
    }

    public function test_only_the_scan_window_is_indexed_for_large_files(): void
    {
        $contents = '';

        for ($i = 0; $i < 500; $i++) {
            $contents .= $this->line('2026-10-15 10:00:00', 'info', "entry {$i} ".str_repeat('x', 100))."#0 trace line\n";
        }

        $path = $this->writeLog('big.log', $contents);
        $index = $this->indexer(scanBytes: 10000)->index($this->file($path));
        $rows = $this->rows($index, $path);

        $this->assertTrue($index->partial);
        $this->assertLessThanOrEqual(10000, filesize($path) - $rows[0]['offset']);
        $this->assertStringStartsWith('[2026-10-15', $rows[0]['text'], 'The window starts at an entry boundary.');
        $this->assertStringContainsString('entry 499', end($rows)['text']);
    }

    public function test_max_entries_keeps_the_newest(): void
    {
        $contents = '';

        for ($i = 0; $i < 50; $i++) {
            $contents .= $this->line('2026-10-15 10:00:00', 'info', "entry {$i}");
        }

        $path = $this->writeLog('a.log', $contents);
        $index = $this->indexer(maxEntries: 10)->index($this->file($path));
        $rows = $this->rows($index, $path);

        $this->assertSame(10, $index->count());
        $this->assertTrue($index->partial);
        $this->assertStringContainsString('entry 40', $rows[0]['text']);
    }

    public function test_growing_files_are_extended_incrementally(): void
    {
        $path = $this->writeLog('a.log', $this->line('2026-10-15 10:00:00', 'info', 'first').$this->line('2026-10-15 10:01:00', 'error', 'second'));
        $indexer = $this->indexer();
        $before = $indexer->index($this->file($path));
        $this->assertSame(2, $before->count());

        // The last entry receives a continuation line, then a new entry arrives.
        file_put_contents($path, "#0 trace\n".$this->line('2026-10-15 10:02:00', 'warning', 'third'), FILE_APPEND);
        $after = $this->indexer()->index($this->file($path));
        $rows = $this->rows($after, $path);

        $this->assertSame(3, $after->count());
        $this->assertSame("[2026-10-15 10:01:00] local.ERROR: second\n#0 trace\n", $rows[1]['text']);
        $this->assertTrue($after->flag(1, LogIndex::FLAG_TRACE));
        $this->assertSame('warning', $rows[2]['level']);
    }

    public function test_growth_beyond_the_scan_window_rebuilds_instead_of_extending(): void
    {
        $path = $this->writeLog('a.log', $this->line('2026-10-15 10:00:00', 'info', 'old'));
        $this->indexer(scanBytes: 2000)->index($this->file($path));

        $contents = '';

        for ($i = 0; $i < 200; $i++) {
            $contents .= $this->line('2026-10-15 11:00:00', 'info', "new {$i} ".str_repeat('x', 50));
        }

        file_put_contents($path, $contents, FILE_APPEND);
        $index = $this->indexer(scanBytes: 2000)->index($this->file($path));

        $this->assertTrue($index->partial);
        $this->assertLessThanOrEqual(2000, filesize($path) - $index->offset(0));
        $this->assertStringContainsString('new 199', substr((string) file_get_contents($path), $index->offset($index->count() - 1)));
    }

    public function test_a_partially_written_last_line_is_corrected_later(): void
    {
        $path = $this->writeLog('a.log', $this->line('2026-10-15 10:00:00', 'info', 'first').'[2026-10-15 10:0');
        $first = $this->indexer()->index($this->file($path));
        $this->assertSame(1, $first->count());

        file_put_contents($path, "1:00] local.ERROR: completed\n", FILE_APPEND);
        $second = $this->indexer()->index($this->file($path));

        $this->assertSame(['info', 'error'], array_column($this->rows($second, $path), 'level'));
    }

    public function test_truncated_files_are_rebuilt(): void
    {
        $path = $this->writeLog('a.log', $this->line('2026-10-15 10:00:00', 'info', 'old one').$this->line('2026-10-15 10:00:00', 'info', 'old two'));
        $this->indexer()->index($this->file($path));

        file_put_contents($path, $this->line('2026-10-16 10:00:00', 'error', 'new'));
        $index = $this->indexer()->index($this->file($path));

        $this->assertSame(1, $index->count());
        $this->assertSame('error', Level::name($index->level(0)));
    }

    public function test_rewritten_files_of_equal_or_larger_size_are_rebuilt(): void
    {
        $path = $this->writeLog('a.log', $this->line('2026-10-15 10:00:00', 'info', 'aaaa'));
        $this->indexer()->index($this->file($path));

        // copytruncate followed by new writes: same inode, larger size, different start.
        file_put_contents($path, $this->line('2026-10-15 11:00:00', 'error', 'bbbb').$this->line('2026-10-15 11:00:00', 'error', 'cccc'));
        $index = $this->indexer()->index($this->file($path));

        $this->assertSame(['error', 'error'], array_column($this->rows($index, $path), 'level'));
    }

    public function test_rotated_files_are_rebuilt(): void
    {
        $path = $this->writeLog('a.log', $this->line('2026-10-15 10:00:00', 'info', 'before rotation'));
        $this->indexer()->index($this->file($path));

        rename($path, $path.'.1');
        $this->writeLog('a.log', $this->line('2026-10-15 10:00:00', 'info', 'before rotation').$this->line('2026-10-15 12:00:00', 'debug', 'after'));
        $index = $this->indexer()->index($this->file($path));

        $this->assertSame(['info', 'debug'], array_column($this->rows($index, $path), 'level'));
    }

    public function test_the_index_survives_a_cache_round_trip(): void
    {
        $path = $this->writeLog('a.log', $this->fixture('laravel-real.log'));
        $original = $this->indexer()->index($this->file($path));
        $restored = LogIndex::fromArray($original->toArray());

        $this->assertNotNull($restored);
        $this->assertEquals($original, $restored);
        $this->assertNull(LogIndex::fromArray(['v' => 1, 'columns' => ['x']]));
        $this->assertNull(LogIndex::fromArray('garbage'));
    }

    public function test_cache_failures_are_ignored(): void
    {
        $cache = new class(new ArrayStore()) extends Repository {
            public function get($key, $default = null): mixed
            {
                throw new \RuntimeException('cache down');
            }

            public function put($key, $value, $ttl = null): bool
            {
                throw new \RuntimeException('cache down');
            }
        };

        $path = $this->writeLog('a.log', $this->line('2026-10-15 10:00:00', 'info', 'x'));

        $this->assertSame(1, $this->indexer(cache: $cache)->index($this->file($path))->count());
    }

    public function test_find_locates_rows_by_offset(): void
    {
        $path = $this->writeLog('a.log', $this->fixture('laravel-real.log'));
        $index = $this->indexer()->index($this->file($path));

        $this->assertSame(4, $index->find($index->offset(4)));
        $this->assertNull($index->find($index->offset(4) + 1));
    }
}
