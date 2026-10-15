<?php

namespace InertiaPixel\LaravelLogViewer\Query;

use InertiaPixel\LaravelLogViewer\Parsing\LogParser;
use InertiaPixel\LaravelLogViewer\Support\Redactor;

/**
 * Full-text matching of entries against the search filter, on redacted text.
 *
 * Plain search is a substring match. Regular expressions (opt-in) are
 * compiled without the "u" modifier, so invalid UTF-8 in logs cannot make
 * them fail, and run under a reduced PCRE backtracking limit.
 */
final class SearchMatcher
{
    private const BACKTRACK_LIMIT = 100000;

    private const DELIMITER = "\x01";

    private ?string $pattern = null;

    private bool $ascii;

    private string|false $previousLimit = false;

    /** Whether a regular expression gave up (backtracking limit) on some entry. */
    public bool $failed = false;

    /** Whether the cheap raw-text check may reject entries before redaction. */
    private bool $prefilter;

    public function __construct(private readonly LogFilters $filters, private readonly Redactor $redactor)
    {
        $this->ascii = ! preg_match('/[\x80-\xFF]/', $filters->search);

        if ($filters->regex) {
            $this->pattern = self::compile($filters->search, $filters->caseSensitive);
        }

        $this->prefilter = $this->pattern === null && ! $redactor->mayIntroduce($filters->search);
    }

    public static function compile(string $pattern, bool $caseSensitive): ?string
    {
        if ($pattern === '' || str_contains($pattern, self::DELIMITER)) {
            return null;
        }

        $compiled = self::DELIMITER.$pattern.self::DELIMITER.($caseSensitive ? '' : 'i');

        return @preg_match($compiled, '') === false ? null : $compiled;
    }

    public function begin(): void
    {
        if ($this->pattern !== null) {
            $this->previousLimit = ini_get('pcre.backtrack_limit');
            ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);
        }
    }

    public function end(): void
    {
        if ($this->previousLimit !== false) {
            ini_set('pcre.backtrack_limit', $this->previousLimit);
            $this->previousLimit = false;
        }
    }

    /**
     * Match a raw entry the way users see it: redacted. Searching the raw
     * text would let anyone confirm a masked value character by character.
     *
     * Redaction only removes the base path and replaces values with
     * replacement text, so when the term is absent from the raw text (base
     * path removed) and cannot overlap replacement text, it cannot be in the
     * redacted text either; those entries skip the costly redaction.
     */
    public function matches(string $raw): bool
    {
        if ($this->prefilter && ! $this->contains($this->redactor->stripBasePath($raw))) {
            return false;
        }

        $text = $this->redactor->apply($raw);
        $haystack = $this->filters->searchTraces ? $text : LogParser::withoutTrace($text);

        if ($this->pattern !== null) {
            $result = @preg_match($this->pattern, $haystack);

            if ($result === false) {
                $this->failed = true;
            }

            return $result === 1;
        }

        return $this->contains($haystack);
    }

    private function contains(string $haystack): bool
    {
        $needle = $this->filters->search;

        if ($this->filters->caseSensitive) {
            return str_contains($haystack, $needle);
        }

        return $this->ascii
            ? stripos($haystack, $needle) !== false
            : mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }
}
