<?php

namespace InertiaPixel\LaravelLogViewer\Support;

/**
 * PSR-3 log levels, ordered by severity, plus "unknown" for entries whose
 * level could not be recognized.
 */
final class Level
{
    public const UNKNOWN = 'unknown';

    /** Level name => compact code stored in the index (higher is more severe). */
    public const CODES = [
        'unknown' => 0,
        'debug' => 1,
        'info' => 2,
        'notice' => 3,
        'warning' => 4,
        'error' => 5,
        'critical' => 6,
        'alert' => 7,
        'emergency' => 8,
    ];

    /** Monolog numeric levels (JsonFormatter "level"). */
    private const MONOLOG = [
        100 => 'debug',
        200 => 'info',
        250 => 'notice',
        300 => 'warning',
        400 => 'error',
        500 => 'critical',
        550 => 'alert',
        600 => 'emergency',
    ];

    private const ALIASES = [
        'warn' => 'warning',
        'err' => 'error',
        'crit' => 'critical',
        'fatal' => 'critical',
        'emerg' => 'emergency',
        'information' => 'info',
        'trace' => 'debug',
    ];

    /**
     * All real levels, most severe first.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
    }

    public static function normalize(string|int|null $level): string
    {
        if (is_int($level) || (is_string($level) && ctype_digit($level))) {
            return self::MONOLOG[(int) $level] ?? self::UNKNOWN;
        }

        $name = strtolower(trim((string) $level));
        $name = self::ALIASES[$name] ?? $name;

        return isset(self::CODES[$name]) ? $name : self::UNKNOWN;
    }

    public static function code(string $level): int
    {
        return self::CODES[$level] ?? 0;
    }

    public static function name(int $code): string
    {
        return array_search($code, self::CODES, true) ?: self::UNKNOWN;
    }

    /** Emergency, alert, critical and error. */
    public static function isError(string $level): bool
    {
        return self::code($level) >= self::CODES['error'];
    }
}
