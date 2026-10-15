<?php

namespace InertiaPixel\LaravelLogViewer\Query;

use InertiaPixel\LaravelLogViewer\Indexing\LogIndex;
use InertiaPixel\LaravelLogViewer\Parsing\LogParser;
use InertiaPixel\LaravelLogViewer\Sources\LogFile;
use InertiaPixel\LaravelLogViewer\Support\Level;
use InertiaPixel\LaravelLogViewer\Support\Redactor;

/**
 * Turns raw entry text into LogEntry objects. Redaction happens here, so
 * no LogEntry can carry unredacted text to a view.
 */
final class EntryFactory
{
    public function __construct(
        private readonly LogParser $parser,
        private readonly Redactor $redactor,
    ) {
    }

    /**
     * Identifier of an entry's content, sent to the browser. It hashes the
     * redacted first line, so it cannot be brute-forced to recover a
     * masked value.
     */
    public function fingerprint(string $raw): string
    {
        return substr(hash('sha256', $this->clean(self::firstLine($raw))), 0, 16);
    }

    private static function firstLine(string $raw): string
    {
        $newline = strpos($raw, "\n");

        return rtrim($newline === false ? $raw : substr($raw, 0, $newline), "\r");
    }

    public function summary(LogFile $file, LogIndex $index, int $row, string $raw, bool $truncated): LogEntry
    {
        $header = $index->flag($row, LogIndex::FLAG_UNPARSED) ? null : $this->parser->header(self::firstLine($raw));
        $summary = LogParser::summarize($this->clean($header === null ? $raw : $header['message']."\n".$this->rest($raw)));

        return $this->make($file, $index, $row, $raw, $truncated, $header, $summary['message'], $summary['exception']);
    }

    public function detail(LogFile $file, LogIndex $index, int $row, string $raw, bool $truncated): LogEntry
    {
        $parsed = $index->flag($row, LogIndex::FLAG_UNPARSED)
            ? array_merge($this->parser->parse(''), ['message' => $raw])
            : $this->parser->parse($raw);

        $exception = $parsed['exception'];

        if ($exception !== null) {
            $exception = [
                'class' => $this->clean($exception['class']),
                'code' => $exception['code'] === null ? null : $this->clean($exception['code']),
                'message' => $this->clean($exception['message']),
                'file' => $exception['file'] === null ? null : $this->clean($exception['file']),
                'line' => $exception['line'],
            ];
        }

        $detail = [
            'message' => $this->clean($parsed['message']),
            'context' => $parsed['context'] === null ? null : $this->structured($parsed['context']),
            'extra' => $parsed['extra'] === null ? null : $this->structured($parsed['extra']),
            'exception' => $exception,
            'stack_trace' => $parsed['stack_trace'] === null ? null : $this->clean($parsed['stack_trace']),
            'raw' => $this->clean($raw),
            'datetime' => $parsed['datetime'] === null ? null : $this->clean($parsed['datetime']),
        ];

        $summary = LogParser::summarize($detail['message']);

        return $this->make($file, $index, $row, $raw, $truncated, $parsed['format'] === LogParser::FORMAT_UNPARSED ? null : $parsed, $summary['message'], $exception['class'] ?? $summary['exception'], $detail);
    }

    /**
     * @param  array<string, mixed>|null  $header
     * @param  array<string, mixed>|null  $detail
     */
    private function make(LogFile $file, LogIndex $index, int $row, string $raw, bool $truncated, ?array $header, string $summary, ?string $exception, ?array $detail = null): LogEntry
    {
        $channel = $index->channelName($index->channel($row));

        return new LogEntry(
            file: $file,
            offset: $index->offset($row),
            hash: $this->fingerprint($raw),
            timestamp: $index->flag($row, LogIndex::FLAG_OWN_TIME) ? $index->time($row) : null,
            level: Level::name($index->level($row)),
            levelName: isset($header['level_name']) && is_string($header['level_name']) ? $this->clean($header['level_name']) : null,
            channel: $channel === null ? null : $this->clean($channel),
            format: is_string($header['format'] ?? null) ? $header['format'] : LogParser::FORMAT_UNPARSED,
            summary: $summary,
            exceptionClass: $exception === null ? null : $this->clean($exception),
            hasTrace: $index->flag($row, LogIndex::FLAG_TRACE),
            truncated: $truncated,
            detail: $detail,
        );
    }

    private function rest(string $raw): string
    {
        $newline = strpos($raw, "\n");

        return $newline === false ? '' : substr($raw, $newline + 1, 4096);
    }

    /**
     * Pretty JSON of a decoded structure: values under sensitive keys are
     * masked whatever their type, then the text rules run on the result.
     *
     * @param  array<mixed>  $data
     */
    private function structured(array $data): string
    {
        return $this->clean((string) json_encode(
            $this->redactor->applyToData($data),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        ));
    }

    /** Valid UTF-8, redacted. */
    private function clean(string $text): string
    {
        return $this->redactor->apply(mb_scrub($text, 'UTF-8'));
    }
}
