<?php

namespace InertiaPixel\LaravelLogViewer\Query;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Named date ranges ("Today", "Last 7 days", ...) resolved in the display
 * timezone. Ranges are inclusive and returned as Unix timestamps.
 */
final class DatePresets
{
    public const ALL = 'all';

    public const CUSTOM = 'custom';

    private const BUILT_IN = [
        'today', 'yesterday', 'last_24_hours', 'last_7_days', 'last_14_days',
        'last_30_days', 'this_week', 'this_month', 'last_month',
    ];

    /** @var array<string, array{label: ?string, days: ?int}> */
    private array $presets = [];

    /**
     * @param  array<int|string, mixed>  $configured
     */
    public function __construct(array $configured, private readonly DateTimeZone $timezone, private readonly int $firstWeekday = 1)
    {
        foreach ($configured as $key => $value) {
            if (is_int($key) && is_string($value) && in_array($value, self::BUILT_IN, true)) {
                $this->presets[$value] = ['label' => null, 'days' => null];
            } elseif (is_string($key) && preg_match('/^[a-z0-9_]{1,40}$/', $key) && is_array($value)) {
                $days = (int) ($value['days'] ?? 0);

                if ($days >= 1 && $days <= 3660) {
                    $this->presets[$key] = ['label' => is_string($value['label'] ?? null) ? $value['label'] : null, 'days' => $days];
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->presets);
    }

    public function has(string $key): bool
    {
        return isset($this->presets[$key]);
    }

    /** Custom label, or null to use the translated built-in label. */
    public function label(string $key): ?string
    {
        return $this->presets[$key]['label'] ?? null;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    public function resolve(string $key): ?array
    {
        if (! $this->has($key)) {
            return null;
        }

        $now = CarbonImmutable::now($this->timezone);
        $today = $now->startOfDay();
        $days = $this->presets[$key]['days'];

        [$from, $to] = match (true) {
            $days !== null => [$today->subDays($days - 1), $now->endOfDay()],
            $key === 'today' => [$today, $now->endOfDay()],
            $key === 'yesterday' => [$today->subDay(), $today->subDay()->endOfDay()],
            $key === 'last_24_hours' => [$now->subDay(), $now],
            $key === 'last_7_days' => [$today->subDays(6), $now->endOfDay()],
            $key === 'last_14_days' => [$today->subDays(13), $now->endOfDay()],
            $key === 'last_30_days' => [$today->subDays(29), $now->endOfDay()],
            $key === 'this_week' => [$today->subDays(($today->dayOfWeek - $this->firstWeekday + 7) % 7), $now->endOfDay()],
            $key === 'this_month' => [$today->startOfMonth(), $now->endOfDay()],
            default => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
        };

        return [$from->getTimestamp(), $to->getTimestamp()];
    }

    /**
     * Inclusive range for calendar dates (Y-m-d) in the display timezone.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public function dates(?string $from, ?string $to): array
    {
        $start = $from === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $from, $this->timezone);
        $end = $to === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $to, $this->timezone);

        return [$start?->startOfDay()->getTimestamp(), $end?->endOfDay()->getTimestamp()];
    }

    public function format(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestamp($timestamp, $this->timezone)->format('Y-m-d');
    }
}
