<?php

namespace InertiaPixel\LaravelLogViewer\Support;

/**
 * Canonicalization and containment checks for log paths.
 *
 * Every path the viewer opens comes from configuration, is resolved with
 * realpath() (which also resolves symlinks) and must stay inside an
 * allowlisted directory. User input never becomes part of a path.
 */
final class PathGuard
{
    /**
     * Canonical absolute directory, or null when it does not exist, is not
     * a directory, or is a filesystem root.
     */
    public static function directory(mixed $path): ?string
    {
        $real = self::resolve($path);

        if ($real === null || ! is_dir($real) || self::isFilesystemRoot($real)) {
            return null;
        }

        return $real;
    }

    /**
     * Canonical absolute path of an existing regular file, or null.
     */
    public static function file(mixed $path): ?string
    {
        $real = self::resolve($path);

        if ($real === null || ! is_file($real) || self::isHidden(basename($real))) {
            return null;
        }

        return $real;
    }

    /**
     * Whether a canonical path is strictly inside a canonical directory.
     */
    public static function isWithin(string $path, string $directory): bool
    {
        $directory = rtrim($directory, '/\\').DIRECTORY_SEPARATOR;

        return str_starts_with($path, $directory) && strlen($path) > strlen($directory);
    }

    /**
     * Whether any segment of a relative path is hidden (".env", ".git/...").
     */
    public static function isHidden(string $relative): bool
    {
        foreach (preg_split('#[/\\\\]#', $relative) ?: [] as $segment) {
            if ($segment !== '' && $segment[0] === '.') {
                return true;
            }
        }

        return false;
    }

    public static function relative(string $path, string $directory): string
    {
        return ltrim(substr($path, strlen(rtrim($directory, '/\\'))), '/\\');
    }

    private static function resolve(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || str_contains($path, "\0") || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return null;
        }

        // Stream wrappers (phar://, http://, data://...) are never allowed.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
            return null;
        }

        $real = @realpath($path);

        return is_string($real) && $real !== '' ? $real : null;
    }

    private static function isFilesystemRoot(string $path): bool
    {
        return $path === '/' || $path === '\\' || (bool) preg_match('#^[A-Za-z]:[\\\\/]?$#', $path) || dirname($path) === $path;
    }
}
