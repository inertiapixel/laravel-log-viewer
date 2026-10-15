<?php

namespace InertiaPixel\LaravelLogViewer\Parsing;

use DateTimeImmutable;
use DateTimeZone;
use InertiaPixel\LaravelLogViewer\Exceptions\InvalidConfigurationException;
use InertiaPixel\LaravelLogViewer\Support\Level;
use Throwable;

/**
 * Recognizes log entries and splits them into their parts.
 *
 * header() is the hot path used while indexing: it inspects one line and
 * reports whether it starts a new entry. parse() runs only for entries that
 * are displayed and extracts the message, context, exception and stack
 * trace. Nothing here assumes a line is well-formed: anything unrecognized
 * stays available verbatim.
 */
final class LogParser
{
    public const FORMAT_LARAVEL = 'laravel';

    public const FORMAT_JSON = 'json';

    public const FORMAT_CUSTOM = 'custom';

    public const FORMAT_UNPARSED = 'unparsed';

    /**
     * Monolog LineFormatter as configured by Laravel:
     * "[2024-01-01 12:00:00] local.ERROR: message {context} {extra}"
     * Also accepts ISO-8601 timestamps with fractions and offsets.
     */
    private const LARAVEL = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:[.,]\d{1,9})?(?:Z|[+-]\d{2}:?\d{2})?)\]\s+([^\s\[\]]+)\.([A-Za-z]+):(?:\s|$)/';

    private const TRACE_MARKERS = ["\n[stacktrace]", "\nStack trace:", "\n#0 "];

    private const MAX_CONTEXT_ATTEMPTS = 24;

    private const MAX_CONTEXT_BYTES = 262144;

    private bool $laravel;

    private bool $json;

    /** @var list<string> */
    private array $patterns;

    private string $lastDatetime = '';

    private ?int $lastTimestamp = null;

    /**
     * @param  list<string>  $formats
     * @param  list<string>  $patterns
     */
    public function __construct(array $formats, array $patterns, private readonly DateTimeZone $timezone)
    {
        $this->laravel = in_array(self::FORMAT_LARAVEL, $formats, true);
        $this->json = in_array(self::FORMAT_JSON, $formats, true);

        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || @preg_match($pattern, '') === false
                || ! str_contains($pattern, '?<datetime>') || ! str_contains($pattern, '?<message>')) {
                throw new InvalidConfigurationException('log-viewer.parsing.patterns must contain valid regular expressions with named "datetime" and "message" groups.');
            }
        }

        $this->patterns = array_values($patterns);
    }

    /**
     * Whether a line starts a new entry, and its header fields.
     *
     * @return array{format: string, datetime: ?string, timestamp: ?int, level: string, level_name: ?string, channel: ?string, message: string, trace: bool}|null
     */
    public function header(string $line): ?array
    {
        if ($line === '') {
            return null;
        }

        $first = $line[0];

        if ($this->laravel && $first === '[' && preg_match(self::LARAVEL, $line, $m, PREG_OFFSET_CAPTURE)) {
            $datetime = $m[1][0];

            return [
                'format' => self::FORMAT_LARAVEL,
                'datetime' => $datetime,
                'timestamp' => $this->timestamp($datetime),
                'level' => Level::normalize($m[3][0]),
                'level_name' => $m[3][0],
                'channel' => $m[2][0],
                'message' => (string) substr($line, strlen($m[0][0])),
                'trace' => false,
            ];
        }

        if ($this->json && $first === '{' && str_ends_with(rtrim($line), '}') && ($fields = $this->jsonHeader($line)) !== null) {
            return $fields;
        }

        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $line, $m) === 1) {
                $datetime = isset($m['datetime']) && $m['datetime'] !== '' ? $m['datetime'] : null;
                $levelName = isset($m['level']) && $m['level'] !== '' ? $m['level'] : null;

                return [
                    'format' => self::FORMAT_CUSTOM,
                    'datetime' => $datetime,
                    'timestamp' => $datetime === null ? null : $this->timestamp($datetime),
                    'level' => Level::normalize($levelName),
                    'level_name' => $levelName,
                    'channel' => isset($m['channel']) && $m['channel'] !== '' ? $m['channel'] : null,
                    'message' => $m['message'] ?? '',
                    'trace' => false,
                ];
            }
        }

        return null;
    }

    /** Whether a continuation line belongs to a stack trace. */
    public static function isTraceLine(string $line): bool
    {
        return str_starts_with($line, '#0 ') || str_starts_with($line, '[stacktrace]') || str_starts_with($line, 'Stack trace:');
    }

    /**
     * The searchable "message" part of a raw entry: everything before its
     * stack trace.
     */
    public static function withoutTrace(string $raw): string
    {
        $cut = strlen($raw);

        foreach (self::TRACE_MARKERS as $marker) {
            $position = strpos($raw, $marker);

            if ($position !== false && $position < $cut) {
                $cut = $position;
            }
        }

        // Single-line JSON entries carry their trace in a "trace" array.
        if ($raw !== '' && $raw[0] === '{' && ($position = strpos($raw, '"trace":[')) !== false && $position < $cut) {
            $cut = $position;
        }

        return substr($raw, 0, $cut);
    }

    /**
     * Normalized first line used to group identical messages
     * ("User 12 not found" and "User 34 not found" share a hash).
     */
    public static function messageHash(string $message): int
    {
        $message = substr($message, 0, 240);

        if (($context = strpos($message, ' {"')) !== false) {
            $message = substr($message, 0, $context);
        }

        return crc32((string) preg_replace('/\d+/', '0', trim($message)));
    }

    /**
     * Split a raw entry (one or more lines) into its parts.
     *
     * "context" and "extra" are the decoded structures (redacted by key later).
     *
     * @return array{format: string, datetime: ?string, timestamp: ?int, level: string, level_name: ?string, channel: ?string, message: string, context: ?array<mixed>, extra: ?array<mixed>, exception: ?array{class: string, code: ?string, message: string, file: ?string, line: ?int}, stack_trace: ?string}
     */
    public function parse(string $raw): array
    {
        $raw = rtrim($raw, "\r\n");
        $newline = strpos($raw, "\n");
        $firstLine = rtrim($newline === false ? $raw : substr($raw, 0, $newline), "\r");
        $rest = $newline === false ? '' : substr($raw, $newline + 1);
        $header = $this->header($firstLine);

        $result = [
            'format' => self::FORMAT_UNPARSED,
            'datetime' => null,
            'timestamp' => null,
            'level' => Level::UNKNOWN,
            'level_name' => null,
            'channel' => null,
            'message' => $raw,
            'context' => null,
            'extra' => null,
            'exception' => null,
            'stack_trace' => null,
        ];

        if ($header === null) {
            return $this->withTrace($result, $raw);
        }

        $result = array_merge($result, array_intersect_key($header, $result));

        if ($header['format'] === self::FORMAT_JSON) {
            return $this->parseJson($result, $firstLine);
        }

        $body = rtrim($header['message'].($rest === '' ? '' : "\n".$rest));

        if ($header['format'] === self::FORMAT_LARAVEL) {
            return $this->parseLaravelBody($result, $body);
        }

        $result['message'] = $body;

        return $this->withTrace($result, $body);
    }

    /**
     * Short single-line summary for tables. Cheap: no JSON decoding.
     *
     * @return array{message: string, exception: ?string}
     */
    public static function summarize(string $message, int $limit = 300): array
    {
        $line = strtok($message, "\n");
        $line = $line === false ? '' : rtrim($line, "\r");
        $exception = null;

        if (preg_match('/\[object\] \(([^\s(]+)\(code:/', substr($message, 0, 4096), $m)) {
            $exception = str_replace('\\\\', '\\', $m[1]);
        }

        if (($context = strpos($line, ' {"')) !== false && $context > 0) {
            $line = substr($line, 0, $context);
        }

        $line = trim($line);

        if (mb_strlen($line) > $limit) {
            $line = rtrim(mb_substr($line, 0, $limit - 1)).'…';
        }

        return ['message' => $line, 'exception' => $exception];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function parseLaravelBody(array $result, string $body): array
    {
        // Monolog without ignoreEmptyContextAndExtra appends " [] []".
        $body = (string) preg_replace('/(?:\s\[\]){1,2}$/', '', $body);
        $result['message'] = $body;

        [$message, $context, $extra] = $this->splitContext($body);

        if ($context === null) {
            return $this->withTrace($result, $body);
        }

        $result['message'] = rtrim($message);

        if (isset($context['exception']) && is_string($context['exception'])) {
            [$result['exception'], $result['stack_trace']] = $this->parseExceptionString($context['exception']);
            unset($context['exception']);
        } elseif (isset($context['exception']) && is_array($context['exception'])) {
            [$result['exception'], $result['stack_trace']] = $this->parseExceptionArray($context['exception']);
            unset($context['exception']);
        }

        $result['context'] = $context === [] ? null : $context;
        $result['extra'] = $extra === null || $extra === [] ? null : $extra;

        if ($result['message'] === '' && $result['exception'] !== null) {
            $result['message'] = $result['exception']['message'];
        }

        return $result;
    }

    /**
     * Find the trailing "{context}" (and optional "{extra}") JSON objects.
     * Laravel writes compact JSON with inline line breaks unescaped, so raw
     * newlines are re-escaped before decoding.
     *
     * @return array{0: string, 1: ?array<mixed>, 2: ?array<mixed>}
     */
    private function splitContext(string $body): array
    {
        if (strlen($body) > self::MAX_CONTEXT_BYTES || ! str_ends_with($body, '}')) {
            return [$body, null, null];
        }

        $positions = [];
        $offset = 0;

        while (count($positions) < self::MAX_CONTEXT_ATTEMPTS && ($position = strpos($body, ' {', $offset)) !== false) {
            $positions[] = $position;
            $offset = $position + 2;
        }

        if (str_starts_with($body, '{')) {
            array_unshift($positions, -1);
        }

        foreach ($positions as $index => $position) {
            $candidate = substr($body, $position + 1);
            $decoded = $this->decodeObject($candidate);

            if ($decoded !== null) {
                return [substr($body, 0, max(0, $position)), $decoded, null];
            }

            // "{context} {extra}": try later split points for the extra object.
            foreach (array_slice($positions, $index + 1, 6) as $split) {
                $context = $this->decodeObject(substr($body, $position + 1, $split - $position - 1));
                $extra = $context === null ? null : $this->decodeObject(substr($body, $split + 1));

                if ($context !== null && $extra !== null) {
                    return [substr($body, 0, max(0, $position)), $context, $extra];
                }
            }
        }

        return [$body, null, null];
    }

    /**
     * @return array<mixed>|null
     */
    private function decodeObject(string $json): ?array
    {
        $json = trim($json);

        if ($json === '' || $json[0] !== '{' || ! str_ends_with($json, '}')) {
            return null;
        }

        $json = str_replace(["\r", "\n", "\t"], ['\r', '\n', '\t'], $json);
        $decoded = json_decode($json, true, 64, JSON_BIGINT_AS_STRING | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * "[object] (Class(code: 0): Message at /path/File.php:12)\n[stacktrace]\n#0 ..."
     *
     * @return array{0: ?array{class: string, code: ?string, message: string, file: ?string, line: ?int}, 1: ?string}
     */
    private function parseExceptionString(string $value): array
    {
        $parts = explode("\n[stacktrace]\n", $value, 2);
        $head = $parts[0];
        $trace = isset($parts[1]) ? rtrim($parts[1]) : null;

        if (preg_match('/^\[object\] \((?<class>[^\s(]+)\(code: (?<code>[^)]*)\): (?<message>.*) at (?<file>[^\n]+?):(?<line>\d+)\)$/s', $head, $m)) {
            return [[
                'class' => $m['class'],
                'code' => $m['code'],
                'message' => $m['message'],
                'file' => $m['file'],
                'line' => (int) $m['line'],
            ], $trace];
        }

        return [['class' => 'Exception', 'code' => null, 'message' => $head, 'file' => null, 'line' => null], $trace];
    }

    /**
     * Monolog's normalized exception: {"class", "message", "code", "file": "path:line", "trace": [...], "previous": {...}}.
     *
     * @param  array<mixed>  $value
     * @return array{0: ?array{class: string, code: ?string, message: string, file: ?string, line: ?int}, 1: ?string}
     */
    private function parseExceptionArray(array $value): array
    {
        $file = is_string($value['file'] ?? null) ? $value['file'] : null;
        $line = null;

        if ($file !== null && preg_match('/^(.*):(\d+)$/', $file, $m)) {
            [$file, $line] = [$m[1], (int) $m[2]];
        }

        $lines = [];
        $current = $value;
        $depth = 0;

        while (is_array($current) && $depth < 10) {
            if ($depth > 0) {
                $lines[] = '[previous exception] '.$this->scalar($current['class'] ?? 'Exception').': '.$this->scalar($current['message'] ?? '');
            }

            foreach (array_values(is_array($current['trace'] ?? null) ? $current['trace'] : []) as $index => $frame) {
                $lines[] = '#'.$index.' '.(is_string($frame) ? $frame : $this->pretty($frame));
            }

            $current = $current['previous'] ?? null;
            $depth++;
        }

        return [[
            'class' => $this->scalar($value['class'] ?? 'Exception'),
            'code' => isset($value['code']) ? $this->scalar($value['code']) : null,
            'message' => $this->scalar($value['message'] ?? ''),
            'file' => $file,
            'line' => $line,
        ], $lines === [] ? null : implode("\n", $lines)];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function parseJson(array $result, string $line): array
    {
        $data = json_decode($line, true, 64, JSON_BIGINT_AS_STRING | JSON_INVALID_UTF8_SUBSTITUTE);

        if (! is_array($data)) {
            return $result;
        }

        $context = is_array($data['context'] ?? null) ? $data['context'] : null;

        if ($context !== null && isset($context['exception'])) {
            [$result['exception'], $result['stack_trace']] = is_array($context['exception'])
                ? $this->parseExceptionArray($context['exception'])
                : $this->parseExceptionString($this->scalar($context['exception']));
            unset($context['exception']);
        }

        $result['message'] = $this->scalar($data['message'] ?? $data['msg'] ?? '');
        $result['context'] = $context === null || $context === [] ? null : $context;
        $result['extra'] = is_array($data['extra'] ?? null) && $data['extra'] !== [] ? $data['extra'] : null;

        return $result;
    }

    /**
     * @return array{format: string, datetime: ?string, timestamp: ?int, level: string, level_name: ?string, channel: ?string, message: string, trace: bool}|null
     */
    private function jsonHeader(string $line): ?array
    {
        $data = json_decode($line, true, 64, JSON_BIGINT_AS_STRING | JSON_INVALID_UTF8_SUBSTITUTE);

        if (! is_array($data) || ! (isset($data['message']) || isset($data['msg']))) {
            return null;
        }

        $level = $data['level_name'] ?? $data['level'] ?? $data['severity'] ?? null;
        $datetime = $data['datetime'] ?? $data['@timestamp'] ?? $data['timestamp'] ?? $data['time'] ?? null;
        $datetime = is_string($datetime) && $datetime !== '' ? $datetime : null;

        if ($level === null && $datetime === null) {
            return null;
        }

        $exception = $data['context']['exception'] ?? null;

        return [
            'format' => self::FORMAT_JSON,
            'datetime' => $datetime,
            'timestamp' => $datetime === null ? null : $this->timestamp($datetime),
            'level' => Level::normalize(is_scalar($level) ? (is_int($level) ? $level : (string) $level) : null),
            'level_name' => is_scalar($level) ? (string) $level : null,
            'channel' => is_scalar($data['channel'] ?? null) ? (string) $data['channel'] : null,
            'message' => $this->scalar($data['message'] ?? $data['msg']),
            'trace' => is_array($exception) ? ! empty($exception['trace']) : (is_string($exception) && str_contains($exception, '[stacktrace]')),
        ];
    }

    /**
     * Detect a stack trace in an otherwise unstructured body.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function withTrace(array $result, string $body): array
    {
        $message = self::withoutTrace($body);

        if (strlen($message) === strlen($body)) {
            return $result;
        }

        $trace = ltrim(substr($body, strlen($message)), "\n");
        $trace = (string) preg_replace('/^(\[stacktrace\]|Stack trace:)\r?\n/', '', $trace);
        // Laravel's JSON context ends with '"}' after the trace.
        $trace = (string) preg_replace('/"\}\s*$/', '', rtrim($trace));

        $result['message'] = rtrim($message);
        $result['stack_trace'] = $trace;

        if (preg_match('/\[object\] \((?<class>[^\s(]+)\(code: (?<code>[^)]*)\): (?<message>.*) at (?<file>[^\n]+?):(?<line>\d+)\)/s', $message, $m)) {
            $unescape = fn (string $value) => str_replace(['\\\\', '\\/', '\\"'], ['\\', '/', '"'], $value);
            $result['exception'] = [
                'class' => $unescape($m['class']),
                'code' => $m['code'],
                'message' => $unescape($m['message']),
                'file' => $unescape($m['file']),
                'line' => (int) $m['line'],
            ];
            $result['stack_trace'] = $unescape($trace);
            $result['message'] = rtrim((string) preg_replace('/\s*\{"exception":"\[object\].*$/s', '', $result['message']));
        }

        return $result;
    }

    private function timestamp(string $datetime): ?int
    {
        if ($datetime === $this->lastDatetime) {
            return $this->lastTimestamp;
        }

        try {
            if (strlen($datetime) === 19 && $datetime[10] === ' ') {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $datetime, $this->timezone);
                // createFromFormat() silently rolls over invalid values (month 13).
                $timestamp = $parsed !== false && $parsed->format('Y-m-d H:i:s') === $datetime ? $parsed->getTimestamp() : null;
            } else {
                $timestamp = (new DateTimeImmutable(str_replace(',', '.', $datetime), $this->timezone))->getTimestamp();
            }
        } catch (Throwable) {
            $timestamp = null;
        }

        $this->lastDatetime = $datetime;

        return $this->lastTimestamp = $timestamp;
    }

    private function pretty(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private function scalar(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) || $value === null ? (string) $value : $this->pretty($value);
    }
}
