<?php

namespace InertiaPixel\LaravelLogViewer\Exceptions;

use RuntimeException;

/**
 * A log file disappeared, changed type, escaped its allowed directory or
 * could not be opened. The message names the file, never its absolute path.
 */
class UnreadableLogFileException extends RuntimeException
{
}
