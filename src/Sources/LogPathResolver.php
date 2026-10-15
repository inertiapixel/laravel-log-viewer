<?php

namespace InertiaPixel\LaravelLogViewer\Sources;

/**
 * Supplies additional log files at runtime (configure the class name in
 * log-viewer.sources.resolver). Returned paths pass the same checks as
 * configured files: they must exist, be regular, non-hidden files, and are
 * canonicalized before use.
 */
interface LogPathResolver
{
    /**
     * @return iterable<string> Absolute file paths.
     */
    public function paths(): iterable;
}
