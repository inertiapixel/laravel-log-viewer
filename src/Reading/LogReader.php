<?php

namespace InertiaPixel\LaravelLogViewer\Reading;

use Generator;
use InertiaPixel\LaravelLogViewer\Exceptions\UnreadableLogFileException;
use InertiaPixel\LaravelLogViewer\Sources\LogFile;
use InertiaPixel\LaravelLogViewer\Support\PathGuard;

/**
 * Bounded, read-only access to log files.
 *
 * Never loads a whole file: lines are streamed with a per-line cap and
 * entries are read by byte offset with a maximum length.
 */
final class LogReader
{
    /** Bytes of a line kept for header detection; the rest is skipped. */
    public const LINE_HEAD = 65536;

    /**
     * Open a file read-only after re-validating it, since it may have been
     * replaced (for example by a symlink) after discovery.
     *
     * @return resource
     */
    public function open(LogFile $file)
    {
        $real = PathGuard::file($file->path);

        if ($real !== $file->path || ! is_readable($real)) {
            throw new UnreadableLogFileException("The log file [{$file->name}] is not readable.");
        }

        $handle = @fopen($real, 'rb');

        if ($handle === false) {
            throw new UnreadableLogFileException("The log file [{$file->name}] could not be opened.");
        }

        // The path may have been swapped for a symlink between the check and
        // fopen(): the opened file must be the regular file at that path.
        clearstatcache(true, $real);
        $opened = fstat($handle);
        $current = @lstat($real);

        if ($opened === false || $current === false || is_link($real)
            || $opened['ino'] !== $current['ino'] || $opened['dev'] !== $current['dev']) {
            fclose($handle);

            throw new UnreadableLogFileException("The log file [{$file->name}] changed while it was being opened.");
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     * @return array{size: int, inode: int}
     */
    public function stat($handle): array
    {
        clearstatcache();
        $stat = fstat($handle);

        return ['size' => (int) ($stat['size'] ?? 0), 'inode' => (int) ($stat['ino'] ?? 0)];
    }

    /**
     * Stream lines between two byte offsets. Yields line start offset =>
     * line head (without the line break, at most LINE_HEAD bytes).
     *
     * With $align the first (possibly partial) line is skipped, for scans
     * that start in the middle of a file.
     *
     * @param  resource  $handle
     * @return Generator<int, string>
     */
    public function lines($handle, int $from, int $to, bool $align = false): Generator
    {
        if (fseek($handle, $from) !== 0) {
            return;
        }

        $position = $from;

        if ($align && $from > 0) {
            // Start at the next line unless $from already is a line start.
            fseek($handle, $from - 1);
            $previous = fread($handle, 1);

            if ($previous !== "\n") {
                while ($position < $to && ($chunk = fgets($handle, self::LINE_HEAD)) !== false) {
                    $position += strlen($chunk);

                    if (str_ends_with($chunk, "\n")) {
                        break;
                    }
                }
            }
        }

        while ($position < $to && ($chunk = fgets($handle, self::LINE_HEAD)) !== false) {
            $start = $position;
            $position += strlen($chunk);
            $head = $chunk;

            while (! str_ends_with($chunk, "\n") && $position < $to && ($chunk = fgets($handle, self::LINE_HEAD)) !== false) {
                $position += strlen($chunk);
            }

            yield $start => rtrim($head, "\r\n");
        }
    }

    /**
     * Read up to $max bytes of the range [$offset, $offset + $length).
     *
     * @param  resource  $handle
     * @return array{0: string, 1: bool} text and whether it was truncated
     */
    public function read($handle, int $offset, int $length, int $max): array
    {
        $wanted = max(0, min($length, $max));

        if ($wanted === 0 || fseek($handle, $offset) !== 0) {
            return ['', $length > 0];
        }

        $buffer = '';

        while (strlen($buffer) < $wanted && ! feof($handle)) {
            $chunk = fread($handle, $wanted - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
        }

        return [$buffer, $length > $max];
    }
}
