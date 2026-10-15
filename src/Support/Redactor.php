<?php

namespace InertiaPixel\LaravelLogViewer\Support;

use InertiaPixel\LaravelLogViewer\Exceptions\InvalidConfigurationException;

/**
 * Best-effort masking of secrets and personal data in displayed log text.
 *
 * Fails closed: when a pattern cannot be applied (for example because PCRE
 * hit its backtracking limit) the whole text is withheld instead of being
 * shown unredacted.
 */
final class Redactor
{
    public const FAILED = '[content withheld: redaction failed]';

    /** @var array<string, string> regex => replacement */
    private array $rules = [];

    /** @var list<string> */
    private array $basePaths = [];

    /** Matches sensitive key names, for structured redaction. */
    private ?string $keyPattern = null;

    private bool $hasCustomRules = false;

    /**
     * @param  array<string, bool>  $builtIn
     * @param  list<string>  $sensitiveKeys
     * @param  array<string, string>  $custom
     */
    public function __construct(
        bool $enabled,
        array $builtIn,
        array $sensitiveKeys,
        array $custom,
        private readonly string $replacement = '[REDACTED]',
        ?string $basePath = null,
    ) {
        if ($basePath !== null && strlen($basePath) > 1) {
            $base = rtrim($basePath, '/\\');
            // Plain, JSON-escaped ("\/") and backslash-escaped ("\\") forms.
            $this->basePaths = array_values(array_unique([
                $base.DIRECTORY_SEPARATOR,
                str_replace('/', '\\/', $base).'\\/',
                str_replace('\\', '\\\\', $base).'\\\\',
            ]));
        }

        if ($enabled) {
            $customRules = $this->customRules($custom);
            $this->hasCustomRules = $customRules !== [];
            $this->rules = $this->builtInRules($builtIn, $sensitiveKeys) + $customRules;
        }
    }

    /** Remove base_path() only (the cheap, length-reducing part of apply()). */
    public function stripBasePath(string $text): string
    {
        return $this->basePaths === [] ? $text : str_replace($this->basePaths, '', $text);
    }

    /**
     * Whether apply() can make $needle appear where it was absent before
     * redaction (other than by removing the base path). False only when
     * provably impossible: no custom rules (their replacements are
     * arbitrary) and $needle cannot overlap any text the rules insert.
     */
    public function mayIntroduce(string $needle): bool
    {
        if ($this->hasCustomRules) {
            return true;
        }

        $needle = mb_strtolower($needle, 'UTF-8');
        $r = $this->replacement;

        foreach ([$r, '"'.$r.'"', '\''.$r.'\'', '\\"'.$r.'\\"', self::FAILED] as $inserted) {
            $inserted = mb_strtolower($inserted, 'UTF-8');

            if (str_contains($inserted, $needle) || str_contains($needle, $inserted)) {
                return true;
            }

            for ($k = 1, $max = min(strlen($needle), strlen($inserted)) - 1; $k <= $max; $k++) {
                if (str_ends_with($needle, substr($inserted, 0, $k)) || str_starts_with($needle, substr($inserted, -$k))) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function fromSettings(Settings $settings, string $basePath): self
    {
        $replacements = $settings->array('privacy.redaction.patterns');

        return new self(
            $settings->bool('privacy.redaction.enabled'),
            array_map(fn ($value) => (bool) $value, $settings->array('privacy.redaction.rules')),
            $settings->strings('privacy.redaction.sensitive_keys'),
            $replacements,
            $settings->string('privacy.redaction.replacement', '[REDACTED]'),
            $settings->bool('privacy.hide_base_path') ? $basePath : null,
        );
    }

    public function apply(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        if ($this->basePaths !== []) {
            $text = str_replace($this->basePaths, '', $text);
        }

        foreach ($this->rules as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $text);

            if ($result === null) {
                return self::FAILED;
            }

            $text = $result;
        }

        return $text;
    }

    /**
     * Redact decoded structures (log context): values under sensitive keys
     * are replaced whole, whatever their type; strings get the text rules.
     */
    public function applyToData(mixed $data, int $depth = 0): mixed
    {
        if (is_string($data)) {
            return $this->apply($data);
        }

        if (! is_array($data)) {
            return $data;
        }

        if ($depth > 32) {
            return $this->replacement;
        }

        $result = [];

        foreach ($data as $key => $value) {
            $result[$key] = is_string($key) && $this->keyPattern !== null && preg_match($this->keyPattern, $key) === 1
                ? $this->replacement
                : $this->applyToData($value, $depth + 1);
        }

        return $result;
    }

    /**
     * @param  array<string, bool>  $enabled
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function builtInRules(array $enabled, array $keys): array
    {
        $r = $this->replacement;
        $rules = [];

        if (($enabled['private_keys'] ?? false) === true) {
            $rules['/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s'] = $this->literal($r);
        }

        if (($enabled['sensitive_keys'] ?? false) === true && $keys !== []) {
            $names = implode('|', array_map(fn ($key) => preg_quote($key, '/'), $keys));
            // Prefixed variants count too: db_password, X-Auth-Token, stripe_secret.
            $key = '(?:[\w-]*[_-])?(?:'.$names.')';
            $value = $this->literal($r);
            $this->keyPattern = '/^(?:[\w.-]*[_.-])?(?:'.$names.')$/i';

            // Values are matched whole: strings, nested objects and lists (balanced, via
            // recursion), scalars. A value cut off by truncation is masked to the end.
            $string = '"(?:[^"\\\\]|\\\\.)*+"';
            $object = '(\{(?:[^{}"]++|"(?:[^"\\\\]|\\\\.)*+"|(?2))*+\})';
            $list = '(\[(?:[^\[\]"]++|"(?:[^"\\\\]|\\\\.)*+"|(?3))*+\])';

            // "password":"x", "cookie":["x"], "auth":{"pass":"x"}, "pin":1234 (JSON).
            $rules['/("'.$key.'"\s*:\s*)(?:'.$string.'|'.$object.'|'.$list.'|["\[{].*|[^,}\]\s"]+)/is'] = '$1"'.$value.'"';
            // {\"password\":\"x\"} (JSON encoded inside a JSON string).
            $rules['/(\\\\"'.$key.'\\\\"\s*:\s*)(?:\\\\"(?:[^"\\\\]|\\\\\\\\|\\\\[^"])*+\\\\"|(\{(?:[^{}]++|(?2))*+\})|(\[(?:[^\[\]]++|(?3))*+\])|\\\\".*|[\[{].*|[^,}\]\s"\\\\]+)/is'] = '$1\\\\"'.$value.'\\\\"';
            // [password] => x and nested "Array ( ... )" (print_r).
            $rules['/(\['.$key.'\]\s*=>\s*)(?:Array\s*(\((?:[^()]++|(?2))*+\))|Array\s*\(.*|[^\r\n]*)/is'] = '$1'.$value;
            // 'password' => 'x' and nested "array ( ... )" (var_export).
            $quoted = '\'(?:[^\'\\\\]|\\\\.)*+\'';
            $rules['/(\''.$key.'\'\s*=>\s*)(?:'.$quoted.'|array\s*(\((?:[^()\']++|'.$quoted.'|(?2))*+\))|array\s*\(.*|\'.*|[^,\r\n)]+)/is'] = '$1\''.$value.'\'';
            // password=x, password="x y", DB_PASSWORD=x (query strings, form bodies, .env-style logs).
            $rules['/(?<![A-Za-z0-9])('.$key.'\s*=\s*)(?:"[^"\r\n]*+"?|\'[^\'\r\n]*+\'?|[^&\s"\',;]+)/i'] = '$1'.$value;
            // Authorization: x / X-Api-Key: x (HTTP headers).
            $rules['/(?<![\w-])('.$key.':[ \t]+)[^\r\n]+/i'] = '$1'.$value;
        }

        if (($enabled['bearer_tokens'] ?? false) === true) {
            $rules['/\b(Bearer|Basic|Token)\s+[A-Za-z0-9\-._~+\/]{8,}=*/'] = '$1 '.$this->literal($r);
        }

        if (($enabled['jwt'] ?? false) === true) {
            $rules['/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}/'] = $this->literal($r);
        }

        if (($enabled['app_keys'] ?? false) === true) {
            $rules['/\bbase64:[A-Za-z0-9+\/]{32,}={0,2}/'] = $this->literal($r);
        }

        if (($enabled['aws_keys'] ?? false) === true) {
            $rules['/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/'] = $this->literal($r);
        }

        if (($enabled['emails'] ?? false) === true) {
            $rules['/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/'] = $this->literal($r);
        }

        if (($enabled['ip_addresses'] ?? false) === true) {
            $rules['/\b(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)\b/'] = $this->literal($r);
            $rules['/\b(?:[0-9a-f]{1,4}:){2,7}[0-9a-f]{1,4}\b/i'] = $this->literal($r);
        }

        return $rules;
    }

    /**
     * @param  array<mixed, mixed>  $custom
     * @return array<string, string>
     */
    private function customRules(array $custom): array
    {
        $rules = [];

        foreach ($custom as $pattern => $replacement) {
            // A list entry ("/regex/") uses the default replacement.
            if (is_int($pattern)) {
                $pattern = $replacement;
                $replacement = $this->literal($this->replacement);
            }

            if (! is_string($pattern) || ! is_string($replacement) || @preg_match($pattern, '') === false) {
                // Fail closed: an invalid rule must not silently disable redaction.
                throw new InvalidConfigurationException('log-viewer.privacy.redaction.patterns contains an invalid regular expression.');
            }

            $rules[$pattern] = $replacement;
        }

        return $rules;
    }

    /** Escape a literal for use as a preg_replace() replacement. */
    private function literal(string $value): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $value);
    }
}
