<?php

namespace InertiaPixel\LaravelLogViewer\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Read-only view of the package configuration.
 *
 * Precedence: package defaults < the application's config/log-viewer.php.
 * Laravel's mergeConfigFrom() only merges top-level keys, so a published
 * file containing part of a section would drop the remaining keys; this
 * class merges associative sections recursively instead. Lists (such as
 * "middleware" or "patterns") are replaced as a whole. Global configuration
 * is never modified.
 */
final class Settings
{
    public const KEY = 'log-viewer';

    /** @var array<string, mixed> */
    private array $values;

    public function __construct(Repository $config)
    {
        $this->values = self::merge(self::defaults(), (array) $config->get(self::KEY, []));
    }

    /**
     * The package defaults. Not memoized: the file uses env() and
     * storage_path(), which belong to the current application.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return require dirname(__DIR__, 2).'/config/log-viewer.php';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function bool(string $key): bool
    {
        return filter_var($this->get($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    public function int(string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        return max($min, min($max, (int) $this->get($key, 0)));
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        return is_string($value) || is_int($value) ? (string) $value : $default;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function array(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? $value : [];
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        return array_values(array_filter($this->array($key), fn ($value) => is_string($value) && $value !== ''));
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function merge(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])
                && ! array_is_list($value) && ! array_is_list($defaults[$key])) {
                $defaults[$key] = self::merge($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }
}
