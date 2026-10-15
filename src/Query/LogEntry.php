<?php

namespace InertiaPixel\LaravelLogViewer\Query;

use InertiaPixel\LaravelLogViewer\Sources\LogFile;

/**
 * One displayed log entry. All text has been redacted and scrubbed to
 * valid UTF-8; it must still be rendered escaped ({{ }}), never as HTML.
 *
 * "detail" is only populated for the entry detail view.
 */
final class LogEntry
{
    /**
     * @param  array{message: string, context: ?string, extra: ?string, exception: ?array{class: string, code: ?string, message: string, file: ?string, line: ?int}, stack_trace: ?string, raw: string, datetime: ?string}|null  $detail
     */
    public function __construct(
        public readonly LogFile $file,
        public readonly int $offset,
        public readonly string $hash,
        public readonly ?int $timestamp,
        public readonly string $level,
        public readonly ?string $levelName,
        public readonly ?string $channel,
        public readonly string $format,
        public readonly string $summary,
        public readonly ?string $exceptionClass,
        public readonly bool $hasTrace,
        public readonly bool $truncated,
        public readonly ?array $detail = null,
    ) {
    }

    /** Stable identifier used in DOM ids. */
    public function key(): string
    {
        return $this->file->id.'-'.$this->offset.'-'.$this->hash;
    }
}
