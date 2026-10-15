<?php

namespace InertiaPixel\LaravelLogViewer\Tests\Unit;

use DateTimeZone;
use InertiaPixel\LaravelLogViewer\Exceptions\InvalidConfigurationException;
use InertiaPixel\LaravelLogViewer\Parsing\LogParser;
use PHPUnit\Framework\TestCase;

class LogParserTest extends TestCase
{
    private function parser(array $patterns = [], string $timezone = 'UTC', array $formats = ['laravel', 'json']): LogParser
    {
        return new LogParser($formats, $patterns, new DateTimeZone($timezone));
    }

    /** Split a fixture into raw entries using the parser's header detection. */
    private function entries(LogParser $parser, string $contents): array
    {
        $entries = [];

        foreach (explode("\n", rtrim($contents, "\n")) as $line) {
            if ($parser->header($line) !== null || $entries === []) {
                $entries[] = $line;
            } else {
                $entries[count($entries) - 1] .= "\n".$line;
            }
        }

        return $entries;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__).'/fixtures/'.$name);
    }

    public function test_laravel_header(): void
    {
        $header = $this->parser()->header('[2026-10-15 09:30:00] production.ERROR: Something failed {"a":1} ');

        $this->assertSame('laravel', $header['format']);
        $this->assertSame('2026-10-15 09:30:00', $header['datetime']);
        $this->assertSame(1792056600, $header['timestamp']);
        $this->assertSame('error', $header['level']);
        $this->assertSame('ERROR', $header['level_name']);
        $this->assertSame('production', $header['channel']);
        $this->assertSame('Something failed {"a":1} ', $header['message']);
    }

    public function test_naive_timestamps_use_the_configured_timezone(): void
    {
        $utc = $this->parser()->header('[2026-01-15 12:00:00] local.INFO: x')['timestamp'];
        $karachi = $this->parser(timezone: 'Asia/Karachi')->header('[2026-01-15 12:00:00] local.INFO: x')['timestamp'];

        $this->assertSame(5 * 3600, $utc - $karachi);
    }

    public function test_iso_timestamps_keep_their_offset(): void
    {
        $parser = $this->parser(timezone: 'Asia/Karachi');

        $this->assertSame(
            $parser->header('[2026-01-15T12:00:00.123456+00:00] app.WARNING: x')['timestamp'],
            $parser->header('[2026-01-15T17:00:00+05:00] app.WARNING: x')['timestamp'],
        );
    }

    public function test_channels_with_dots_and_unknown_levels(): void
    {
        $parser = $this->parser();

        $header = $parser->header('[2026-10-15 09:30:00] my.app.WARNING: a.b: c');
        $this->assertSame('my.app', $header['channel']);
        $this->assertSame('warning', $header['level']);
        $this->assertSame('a.b: c', $header['message']);

        $this->assertSame('unknown', $parser->header('[2026-10-15 09:30:00] local.VERBOSE: x')['level']);
        $this->assertSame('debug', $parser->header('[2026-10-15 09:30:00] local.TRACE: x')['level']);
        $this->assertSame('', $parser->header('[2026-10-15 09:30:00] local.INFO:')['message']);
    }

    public function test_non_header_lines(): void
    {
        $parser = $this->parser();

        foreach (['', '#0 {main}', '[stacktrace]', 'random text', '[not a date] local.ERROR: x', '[2026-10-15 09:30:00] no level here', '{"not":"a log"}', '{broken json'] as $line) {
            $this->assertNull($parser->header($line), $line);
        }
    }

    public function test_real_laravel_log_is_split_into_entries(): void
    {
        $parser = $this->parser();
        $entries = $this->entries($parser, $this->fixture('laravel-real.log'));

        $this->assertCount(8, $entries);
        $this->assertSame(['info', 'debug', 'warning', 'notice', 'error', 'critical', 'info', 'error'], array_map(fn ($raw) => $parser->parse($raw)['level'], $entries));
    }

    public function test_context_and_exception_with_previous(): void
    {
        $parser = $this->parser();
        $entry = $parser->parse($this->entries($parser, $this->fixture('laravel-real.log'))[4]);

        $this->assertSame('Payment gateway timeout', $entry['message']);
        $this->assertSame('RuntimeException', $entry['exception']['class']);
        $this->assertSame('504', $entry['exception']['code']);
        $this->assertSame('Payment gateway timeout', $entry['exception']['message']);
        $this->assertSame('/var/www/html/app/Services/PaymentService.php', $entry['exception']['file']);
        $this->assertSame(15, $entry['exception']['line']);
        $this->assertStringContainsString('[previous exception] [object] (InvalidArgumentException', $entry['stack_trace']);
        $this->assertStringStartsWith('#0 {main}', $entry['stack_trace']);
        $this->assertSame(['order_id' => 'A-1001'], $entry['context']);
    }

    public function test_simple_context(): void
    {
        $entry = $this->parser()->parse('[2026-10-15 09:30:00] production.INFO: User logged in {"user_id":42,"ip":"203.0.113.9"} ');

        $this->assertSame('User logged in', $entry['message']);
        $this->assertSame(['user_id' => 42, 'ip' => '203.0.113.9'], $entry['context']);
        $this->assertNull($entry['exception']);
        $this->assertNull($entry['stack_trace']);
    }

    public function test_context_and_extra(): void
    {
        $entry = $this->parser()->parse('[2026-10-15 09:30:00] local.INFO: Hello {"a":1} {"request_id":"r-1"}');

        $this->assertSame('Hello', $entry['message']);
        $this->assertSame(['a' => 1], $entry['context']);
        $this->assertSame(['request_id' => 'r-1'], $entry['extra']);
    }

    public function test_braces_inside_the_message_are_kept(): void
    {
        $entry = $this->parser()->parse('[2026-10-15 09:30:00] local.INFO: Payload {not json} received {"a":1}');

        $this->assertSame('Payload {not json} received', $entry['message']);
        $this->assertSame(['a' => 1], $entry['context']);
    }

    public function test_monolog_default_empty_context_and_extra(): void
    {
        $entry = $this->parser()->parse('[2026-10-15T09:30:00.000000+00:00] app.NOTICE: Plain [] []');

        $this->assertSame('Plain', $entry['message']);
        $this->assertNull($entry['context']);
    }

    public function test_multiline_message(): void
    {
        $parser = $this->parser();
        $entry = $parser->parse($this->entries($parser, $this->fixture('laravel-real.log'))[5]);

        $this->assertSame("Multi-line message\nsecond line of the message\nthird line", $entry['message']);
        $this->assertNull($entry['stack_trace']);
    }

    public function test_namespaced_exception_classes_are_unescaped(): void
    {
        $raw = '[2026-10-15 09:30:00] local.ERROR: SQLSTATE[42S02] {"userId":1,"exception":"[object] (Illuminate\\\\Database\\\\QueryException(code: 42S02): SQLSTATE[42S02]: Base table not found at /var/www/html/vendor/laravel/framework/src/Illuminate/Database/Connection.php:825)'
            ."\n[stacktrace]\n#0 /var/www/html/vendor/laravel/framework/src/Illuminate/Database/Connection.php(779): Illuminate\\\\Database\\\\Connection->runQueryCallback()\n#1 {main}\n\"} ";

        $entry = $this->parser()->parse($raw);

        $this->assertSame('SQLSTATE[42S02]', $entry['message']);
        $this->assertSame('Illuminate\\Database\\QueryException', $entry['exception']['class']);
        $this->assertSame('42S02', $entry['exception']['code']);
        $this->assertSame(825, $entry['exception']['line']);
        $this->assertStringContainsString('Illuminate\\Database\\Connection->runQueryCallback()', $entry['stack_trace']);
        $this->assertSame(['userId' => 1], $entry['context']);

        $summary = LogParser::summarize($raw);
        $this->assertSame('Illuminate\\Database\\QueryException', $summary['exception']);
        $this->assertStringStartsWith('[2026-10-15 09:30:00] local.ERROR: SQLSTATE[42S02]', $summary['message']);
    }

    public function test_truncated_or_malformed_context_falls_back_to_trace_markers(): void
    {
        $raw = '[2026-10-15 09:30:00] local.ERROR: Boom {"exception":"[object] (App\\\\Exceptions\\\\Broken(code: 0): Boom at /app/x.php:3)'
            ."\n[stacktrace]\n#0 /app/y.php(10): App\\\\Foo->bar()\n#1 {main}";

        $entry = $this->parser()->parse($raw);

        $this->assertSame('Boom', $entry['message']);
        $this->assertSame('App\\Exceptions\\Broken', $entry['exception']['class']);
        $this->assertSame("#0 /app/y.php(10): App\\Foo->bar()\n#1 {main}", $entry['stack_trace']);
    }

    public function test_native_php_stack_trace(): void
    {
        $raw = "[2026-10-15 09:30:00] local.ERROR: PHP Fatal error: Uncaught Exception: oops in /app/a.php:3\nStack trace:\n#0 {main}\n  thrown in /app/a.php on line 3";

        $entry = $this->parser()->parse($raw);

        $this->assertSame('PHP Fatal error: Uncaught Exception: oops in /app/a.php:3', $entry['message']);
        $this->assertSame("#0 {main}\n  thrown in /app/a.php on line 3", $entry['stack_trace']);
    }

    public function test_monolog_json_lines(): void
    {
        $parser = $this->parser();
        [$info, $error] = explode("\n", trim($this->fixture('monolog-json.log')));

        $header = $parser->header($info);
        $this->assertSame('json', $header['format']);
        $this->assertSame('info', $header['level']);
        $this->assertSame('production', $header['channel']);
        $this->assertSame(1792056600, $header['timestamp']);

        $entry = $parser->parse($error);
        $this->assertSame('JSON failure', $entry['message']);
        $this->assertSame('DomainException', $entry['exception']['class']);
        $this->assertSame('/var/www/html/app/Services/PaymentService.php', $entry['exception']['file']);
        $this->assertSame(21, $entry['exception']['line']);
        $this->assertTrue($parser->header($error)['trace'] || $entry['stack_trace'] === null);
    }

    public function test_json_with_numeric_level_and_disabled_format(): void
    {
        $line = '{"msg":"hello","level":400,"time":"2026-10-15T09:30:00Z"}';

        $this->assertSame('error', $this->parser()->header($line)['level']);
        $this->assertNull($this->parser(formats: ['laravel'])->header($line));
    }

    public function test_custom_patterns(): void
    {
        $parser = $this->parser(['/^(?<datetime>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) (?<level>[A-Z]+) \[(?<channel>[^\]]+)\] (?<message>.*)$/']);

        $header = $parser->header('2026-10-15 09:30:00 WARN [worker] Queue is backing up');

        $this->assertSame('custom', $header['format']);
        $this->assertSame('warning', $header['level']);
        $this->assertSame('worker', $header['channel']);
        $this->assertSame('Queue is backing up', $header['message']);
        $this->assertSame(1792056600, $header['timestamp']);
    }

    public function test_invalid_custom_patterns_are_rejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->parser(['/(?<datetime>unclosed']);
    }

    public function test_patterns_must_name_datetime_and_message(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->parser(['/^(?<msg>.*)$/']);
    }

    public function test_unparsed_text_is_kept_verbatim(): void
    {
        $entry = $this->parser()->parse("just some text\nwith two lines");

        $this->assertSame('unparsed', $entry['format']);
        $this->assertSame('unknown', $entry['level']);
        $this->assertSame("just some text\nwith two lines", $entry['message']);
        $this->assertNull($entry['timestamp']);
    }

    public function test_invalid_timestamps_do_not_break_parsing(): void
    {
        $header = $this->parser()->header('[2026-13-45 99:99:99] local.INFO: x');

        $this->assertNotNull($header);
        $this->assertNull($header['timestamp']);
    }

    public function test_message_hash_groups_messages_that_differ_in_numbers(): void
    {
        $this->assertSame(LogParser::messageHash('User 12 not found {"a":1}'), LogParser::messageHash('User 345 not found {"b":2}'));
        $this->assertNotSame(LogParser::messageHash('User not found'), LogParser::messageHash('Order not found'));
    }

    public function test_without_trace(): void
    {
        $this->assertSame('msg {"exception":"x', LogParser::withoutTrace("msg {\"exception\":\"x\n[stacktrace]\n#0 a.php"));
        $this->assertSame('plain', LogParser::withoutTrace('plain'));
    }
}
