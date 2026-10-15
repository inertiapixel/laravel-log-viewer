<?php

namespace InertiaPixel\LaravelLogViewer\Sources;

/**
 * An allowlisted log file. "path" is canonical and server-only: it is never
 * rendered unless log-viewer.ui.show_file_paths is enabled. The browser only
 * ever sees "id" (an opaque hash) and "name" (relative to its directory).
 */
final class LogFile
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $path,
        public readonly string $location,
        public readonly int $size,
        public readonly int $modifiedAt,
        public readonly bool $readable,
    ) {
    }

    public static function idFor(string $canonicalPath): string
    {
        return substr(hash('sha256', $canonicalPath), 0, 16);
    }
}
