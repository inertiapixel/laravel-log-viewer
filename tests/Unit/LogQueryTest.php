<?php

namespace InertiaPixel\LaravelLogViewer\Tests\Unit;

use Carbon\CarbonImmutable;
use InertiaPixel\LaravelLogViewer\Query\FilterFactory;
use InertiaPixel\LaravelLogViewer\Query\LogFilters;
use InertiaPixel\LaravelLogViewer\Query\LogQuery;
use InertiaPixel\LaravelLogViewer\Query\QueryResult;
use InertiaPixel\LaravelLogViewer\Sources\LogFile;
use InertiaPixel\LaravelLogViewer\Tests\TestCase;

class LogQueryTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: QueryResult, 1: array<string, string>, 2: LogFilters}
     */
    private function runQuery(array $input = [], array $config = []): array
    {
        foreach ($config as $key => $value) {
            config()->set('log-viewer.'.$key, $value);
        }

        $this->app->forgetScopedInstances();
        [$filters, $errors] = $this->app->make(FilterFactory::class)->make($input);

        return [$this->app->make(LogQuery::class)->run($filters), $errors, $filters];
    }

    private function messages(QueryResult $result): array
    {
        return array_map(fn ($entry) => $entry->summary, $result->entries);
    }

    private function sample(): void
    {
        $this->writeLog('app.log', implode('', [
            $this->line('2026-10-13 23:30:00', 'debug', 'Cache warmed'),
            $this->line('2026-10-14 08:00:00', 'info', 'User 1 logged in'),
            $this->line('2026-10-14 09:00:00', 'warning', 'Disk almost full'),
            $this->line('2026-10-14 10:00:00', 'error', 'Payment failed {"exception":"[object] (RuntimeException(code: 0): Payment failed at /app/Pay.php:9)')
                ."[stacktrace]\n#0 /app/Gateway.php(12): charge()\n#1 {main}\n\"} \n",
            $this->line('2026-10-15 00:30:00', 'info', 'User 2 logged in', 'worker'),
            $this->line('2026-10-15 12:00:00', 'critical', 'Database unreachable'),
        ]), strtotime('2026-10-15 12:00:00 UTC'));
    }

    public function test_entries_newest_first_with_statistics(): void
    {
        $this->sample();
        [$result] = $this->runQuery();

        $this->assertSame(6, $result->total);
        $this->assertSame(['Database unreachable', 'User 2 logged in', 'Payment failed', 'Disk almost full', 'User 1 logged in', 'Cache warmed'], $this->messages($result));
        $this->assertSame(1, $result->levels['critical']);
        $this->assertSame(2, $result->levels['info']);
        $this->assertSame(0, $result->levels['alert']);
        $this->assertSame(2, $result->errorCount());
        $this->assertSame(['local' => 5, 'worker' => 1], $result->channels);
        $this->assertSame(['local', 'worker'], $result->availableChannels);
        $this->assertSame([['message' => 'User 2 logged in', 'count' => 2, 'level' => 'info']], $result->topMessages);
        $this->assertSame('RuntimeException', $result->entries[2]->exceptionClass);
        $this->assertTrue($result->entries[2]->hasTrace);
    }

    public function test_oldest_first(): void
    {
        $this->sample();
        [$result] = $this->runQuery(['sort' => 'oldest', 'per_page' => 25]);

        $this->assertSame('Cache warmed', $result->entries[0]->summary);
    }

    public function test_level_and_channel_filters(): void
    {
        $this->sample();

        [$result] = $this->runQuery(['levels' => ['error', 'critical']]);
        $this->assertSame(['Database unreachable', 'Payment failed'], $this->messages($result));

        [$result] = $this->runQuery(['levels' => 'info,debug']);
        $this->assertSame(3, $result->total);

        [$result] = $this->runQuery(['channel' => 'worker']);
        $this->assertSame(['User 2 logged in'], $this->messages($result));

        [$result] = $this->runQuery(['channel' => 'missing']);
        $this->assertSame(0, $result->total);
    }

    public function test_search_is_case_insensitive_by_default(): void
    {
        $this->sample();

        [$result] = $this->runQuery(['q' => 'LOGGED IN']);
        $this->assertSame(2, $result->total);

        [$result] = $this->runQuery(['q' => 'LOGGED IN', 'case' => '1']);
        $this->assertSame(0, $result->total);
    }

    public function test_stack_traces_are_searched_only_when_requested(): void
    {
        $this->sample();

        [$result] = $this->runQuery(['q' => 'Gateway.php']);
        $this->assertSame(0, $result->total);

        [$result] = $this->runQuery(['q' => 'Gateway.php', 'traces' => '1']);
        $this->assertSame(['Payment failed'], $this->messages($result));

        [$result] = $this->runQuery(['q' => 'RuntimeException']);
        $this->assertSame(1, $result->total, 'The exception summary precedes the trace.');
    }

    public function test_search_matches_the_displayed_redacted_text(): void
    {
        $this->writeLog('app.log', $this->line('2026-10-15 10:00:00', 'info', 'Login {"password":"hunter2"}').$this->line('2026-10-15 10:00:01', 'info', 'Login {"cvv":737}').$this->line('2026-10-15 10:00:02', 'info', 'plain'));

        [$result] = $this->runQuery(['q' => 'hunter2']);
        $this->assertSame(0, $result->total);

        [$result] = $this->runQuery(['q' => '[REDACTED]']);
        $this->assertSame(2, $result->total);

        [$result] = $this->runQuery(['q' => '"cvv":"']);
        $this->assertSame(1, $result->total, 'Quotes inserted by redaction are searchable as shown.');

        [$result] = $this->runQuery(['q' => 'hunt', 'regex' => '1'], ['filters.search.regex' => true]);
        $this->assertSame(0, $result->total, 'Regular expressions also run on redacted text.');
    }

    public function test_regex_search_is_opt_in(): void
    {
        $this->sample();

        [$result] = $this->runQuery(['q' => 'User \d', 'regex' => '1']);
        $this->assertSame(0, $result->total, 'Without config the pattern is a literal string.');

        [$result] = $this->runQuery(['q' => 'User \d', 'regex' => '1'], ['filters.search.regex' => true]);
        $this->assertSame(2, $result->total);

        [$result, $errors] = $this->runQuery(['q' => 'User (', 'regex' => '1'], ['filters.search.regex' => true]);
        $this->assertArrayHasKey('q', $errors);
        $this->assertSame(6, $result->total);
    }

    public function test_multibyte_search(): void
    {
        $this->writeLog('app.log', $this->line('2026-10-15 10:00:00', 'info', 'Ünïcödé Straße').$this->line('2026-10-15 10:00:01', 'info', 'plain'));

        [$result] = $this->runQuery(['q' => 'ünÏcÖdé']);
        $this->assertSame(1, $result->total);
    }

    public function test_date_ranges_use_the_display_timezone(): void
    {
        $this->sample();

        [$result] = $this->runQuery(['preset' => 'custom', 'from' => '2026-10-14', 'to' => '2026-10-14']);
        $this->assertSame(['Payment failed', 'Disk almost full', 'User 1 logged in'], $this->messages($result));

        // In Asia/Karachi (UTC+5), 2026-10-13 23:30 UTC is already 2026-10-14
        // and 2026-10-15 00:30 UTC is 05:30 on the 15th.
        [$result] = $this->runQuery(['preset' => 'custom', 'from' => '2026-10-14', 'to' => '2026-10-14'], ['ui.timezone' => 'Asia/Karachi']);
        $this->assertSame(['Payment failed', 'Disk almost full', 'User 1 logged in', 'Cache warmed'], $this->messages($result));
    }

    public function test_presets(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 13:00:00');
        $this->sample();

        [$result, , $filters] = $this->runQuery(['preset' => 'today']);
        $this->assertSame('today', $filters->preset);
        $this->assertSame(['Database unreachable', 'User 2 logged in'], $this->messages($result));

        [$result] = $this->runQuery(['preset' => 'yesterday']);
        $this->assertSame(3, $result->total);

        [$result] = $this->runQuery(['preset' => 'last_24_hours'], ['filters.date_range.presets' => ['last_24_hours']]);
        $this->assertSame(['Database unreachable', 'User 2 logged in'], $this->messages($result));

        [$result] = $this->runQuery(['preset' => 'last_90_days'], ['filters.date_range.presets' => ['last_90_days' => ['label' => 'Last 90 days', 'days' => 90]]]);
        $this->assertSame(6, $result->total);
    }

    public function test_the_default_preset_applies_without_date_parameters(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 13:00:00');
        $this->sample();

        [$result, , $filters] = $this->runQuery([], ['filters.date_range.default' => 'today']);
        $this->assertSame('today', $filters->preset);
        $this->assertSame(2, $result->total);

        [$result] = $this->runQuery(['preset' => 'all'], ['filters.date_range.default' => 'today']);
        $this->assertSame(6, $result->total);
    }

    public function test_a_named_preset_wins_over_dates(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 13:00:00');
        $this->sample();

        [$result, , $filters] = $this->runQuery(['preset' => 'today', 'from' => '2026-10-13', 'to' => '2026-10-13']);
        $this->assertSame('today', $filters->preset);
        $this->assertNull($filters->fromDate);
        $this->assertSame(2, $result->total);
    }

    public function test_pagination_and_page_size_limits(): void
    {
        $contents = '';

        for ($i = 1; $i <= 60; $i++) {
            $contents .= $this->line(sprintf('2026-10-15 10:%02d:00', $i - 1), 'info', "entry {$i}");
        }

        $this->writeLog('app.log', $contents);

        [$result] = $this->runQuery(['per_page' => 25, 'page' => 2]);
        $this->assertSame(60, $result->total);
        $this->assertSame(3, $result->lastPage());
        $this->assertSame('entry 35', $result->entries[0]->summary);
        $this->assertSame(26, $result->firstItem());
        $this->assertSame(50, $result->lastItem());

        [$result] = $this->runQuery(['per_page' => 25, 'page' => 99]);
        $this->assertSame(3, $result->page, 'Out-of-range pages clamp to the last page.');
        $this->assertCount(10, $result->entries);

        [$result, $errors] = $this->runQuery(['per_page' => 10000]);
        $this->assertArrayHasKey('per_page', $errors);
        $this->assertSame(50, $result->perPage);
    }

    public function test_entries_from_several_files_are_merged_by_time(): void
    {
        $this->writeLog('a.log', $this->line('2026-10-15 10:00:00', 'info', 'a1').$this->line('2026-10-15 12:00:00', 'info', 'a2'));
        $this->writeLog('b.log', $this->line('2026-10-15 11:00:00', 'info', 'b1').$this->line('2026-10-15 13:00:00', 'info', 'b2'));

        [$result] = $this->runQuery();
        $this->assertSame(['b2', 'a2', 'b1', 'a1'], $this->messages($result));
        $this->assertSame(2, count($result->scannedFiles));

        [$result] = $this->runQuery(['sort' => 'oldest', 'per_page' => 25, 'page' => 1]);
        $this->assertSame(['a1', 'b1', 'a2', 'b2'], $this->messages($result));
    }

    public function test_out_of_order_entries_within_a_file_are_sorted(): void
    {
        $this->writeLog('a.log', $this->line('2026-10-15 12:00:00', 'info', 'noon').$this->line('2026-10-15 09:00:00', 'info', 'morning').$this->line('2026-10-15 18:00:00', 'info', 'evening'));

        [$result] = $this->runQuery();
        $this->assertSame(['evening', 'noon', 'morning'], $this->messages($result));

        [$result] = $this->runQuery(['sort' => 'oldest']);
        $this->assertSame(['morning', 'noon', 'evening'], $this->messages($result));
    }

    public function test_every_page_of_a_multi_file_merge_is_consistent(): void
    {
        // Equal timestamps across files exercise the tie-breaking in both walk directions.
        foreach (['a', 'b', 'c'] as $offset => $name) {
            $contents = '';

            for ($i = 0; $i < 23; $i++) {
                $contents .= $this->line(sprintf('2026-10-15 10:%02d:00', intdiv($i + $offset, 2)), 'info', "{$name}{$i}");
            }

            $this->writeLog("{$name}.log", $contents);
        }

        foreach (['newest', 'oldest'] as $sort) {
            [$all] = $this->runQuery(['sort' => $sort, 'per_page' => 100]);
            $paged = [];

            for ($page = 1; $page <= 3; $page++) {
                [$result] = $this->runQuery(['sort' => $sort, 'per_page' => 25, 'page' => $page]);
                array_push($paged, ...$this->messages($result));
            }

            $this->assertCount(69, $paged);
            $this->assertSame($this->messages($all), $paged, "Pages concatenate to the full {$sort} order.");
        }
    }

    public function test_file_filter(): void
    {
        $this->writeLog('a.log', $this->line('2026-10-15 10:00:00', 'info', 'from a'));
        $b = $this->writeLog('b.log', $this->line('2026-10-15 11:00:00', 'info', 'from b'));

        [$result] = $this->runQuery(['file' => LogFile::idFor($b)]);
        $this->assertSame(['from b'], $this->messages($result));

        [$result, $errors] = $this->runQuery(['file' => str_repeat('0', 16)]);
        $this->assertArrayHasKey('file', $errors);
        $this->assertSame(2, $result->total);

        [, $errors] = $this->runQuery(['file' => '../../.env']);
        $this->assertArrayHasKey('file', $errors);
    }

    public function test_latest_file_default(): void
    {
        $this->writeLog('old.log', $this->line('2026-10-14 10:00:00', 'info', 'old'), strtotime('2026-10-14 10:00:00'));
        $this->writeLog('new.log', $this->line('2026-10-15 10:00:00', 'info', 'new'), strtotime('2026-10-15 10:00:00'));

        [$result] = $this->runQuery([], ['filters.files.default' => 'latest']);
        $this->assertSame(['new'], $this->messages($result));
    }

    public function test_files_untouched_since_before_the_range_are_skipped(): void
    {
        $this->writeLog('old.log', $this->line('2026-09-01 10:00:00', 'info', 'old'), strtotime('2026-09-01 10:00:00'));
        $this->writeLog('new.log', $this->line('2026-10-15 10:00:00', 'info', 'new'), strtotime('2026-10-15 10:00:00'));

        [$result] = $this->runQuery(['preset' => 'custom', 'from' => '2026-10-01']);
        $this->assertSame(['new.log'], array_map(fn ($file) => $file->name, $result->scannedFiles));
    }

    public function test_files_per_query_limit(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->writeLog("f{$i}.log", $this->line("2026-10-1{$i} 10:00:00", 'info', "file {$i}"), strtotime("2026-10-1{$i} 10:00:00"));
        }

        [$result] = $this->runQuery([], ['limits.max_files_per_query' => 2]);
        $this->assertSame(['file 4', 'file 3'], $this->messages($result));
        $this->assertStringContainsString('2 older file(s) were skipped', implode(' ', $result->notices));
    }

    public function test_partial_scans_are_reported(): void
    {
        $contents = '';

        for ($i = 0; $i < 200; $i++) {
            $contents .= $this->line('2026-10-15 10:00:00', 'info', 'entry '.str_repeat('x', 100));
        }

        $this->writeLog('big.log', $contents);
        [$result] = $this->runQuery([], ['limits.scan_bytes' => 5000]);

        $this->assertTrue($result->partial);
        $this->assertLessThan(200, $result->total);
        $this->assertStringContainsString('Only the most recent 4.9 KB of big.log were scanned', implode(' ', $result->notices));
    }

    public function test_unreadable_files_are_skipped_with_a_notice(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Permissions are not enforced for root.');
        }

        $this->writeLog('ok.log', $this->line('2026-10-15 10:00:00', 'info', 'readable'));
        $locked = $this->writeLog('locked.log', $this->line('2026-10-15 10:00:00', 'info', 'secret'));
        chmod($locked, 0000);

        try {
            [$result] = $this->runQuery();
            $this->assertSame(['readable'], $this->messages($result));
            $this->assertStringContainsString('locked.log is not readable', implode(' ', $result->notices));
        } finally {
            chmod($locked, 0644);
        }
    }

    public function test_trend_buckets(): void
    {
        $this->sample();
        [$result] = $this->runQuery(['preset' => 'custom', 'from' => '2026-10-13', 'to' => '2026-10-15']);

        // Three days in at most 31 buckets: 3-hour buckets.
        $this->assertSame('3 hours', $result->trend['interval']);
        $buckets = $result->trend['buckets'];
        $this->assertSame(6, array_sum(array_map(fn ($b) => $b['errors'] + $b['warnings'] + $b['other'], $buckets)));
        $this->assertSame(2, array_sum(array_column($buckets, 'errors')));
        $this->assertSame(1, array_sum(array_column($buckets, 'warnings')));
        $this->assertLessThanOrEqual(31, count($buckets));
    }

    public function test_daily_trend_for_longer_ranges(): void
    {
        $this->sample();
        [$result] = $this->runQuery(['preset' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-15']);

        $this->assertSame('day', $result->trend['interval']);
        $this->assertCount(15, $result->trend['buckets']);
        $this->assertSame('Oct 14', $result->trend['buckets'][13]['label']);
        $this->assertSame(3, $result->trend['buckets'][13]['errors'] + $result->trend['buckets'][13]['warnings'] + $result->trend['buckets'][13]['other']);
    }

    public function test_find_returns_redacted_detail_and_rejects_stale_hashes(): void
    {
        $this->writeLog('app.log', $this->line('2026-10-15 10:00:00', 'error', 'Login failed {"password":"hunter2","user":"jane"}'));
        [$result] = $this->runQuery();
        $entry = $result->entries[0];

        $detail = $this->app->make(LogQuery::class)->find($entry->file->id, $entry->offset, $entry->hash);
        $this->assertSame('Login failed', $detail->detail['message']);
        $this->assertSame(['password' => '[REDACTED]', 'user' => 'jane'], json_decode($detail->detail['context'], true));
        $this->assertStringNotContainsString('hunter2', $detail->detail['raw']);

        $this->assertNull($this->app->make(LogQuery::class)->find($entry->file->id, $entry->offset, 'deadbeef'));
        $this->assertNull($this->app->make(LogQuery::class)->find($entry->file->id, $entry->offset + 1, $entry->hash));
    }

    public function test_invalid_input_is_dropped_with_messages(): void
    {
        $this->sample();
        [$result, $errors] = $this->runQuery([
            'levels' => ['error', 'nope'],
            'sort' => 'random()',
            'from' => '2026-02-30',
            'q' => "bad\x00byte",
            'page' => -3,
        ]);

        $this->assertEqualsCanonicalizing(['levels', 'sort', 'from', 'q', 'page'], array_keys($errors));
        $this->assertSame(6, $result->total);
    }

    public function test_reversed_ranges_are_rejected(): void
    {
        $this->sample();
        [, $errors, $filters] = $this->runQuery(['preset' => 'custom', 'from' => '2026-10-15', 'to' => '2026-10-01']);

        $this->assertArrayHasKey('to', $errors);
        $this->assertNull($filters->toDate);
    }

    public function test_shareable_query_omits_defaults_and_server_values(): void
    {
        [, , $filters] = $this->runQuery(['q' => 'boom', 'levels' => ['error'], 'per_page' => 50, 'sort' => 'newest', 'preset' => 'custom', 'from' => '2026-10-01']);

        $this->assertSame(['levels' => ['error'], 'q' => 'boom', 'preset' => 'custom', 'from' => '2026-10-01'], $filters->query());
        $this->assertSame(['levels' => ['error'], 'q' => 'boom', 'preset' => 'custom', 'from' => '2026-10-01', 'page' => 3], $filters->query(['page' => 3]));
    }
}
