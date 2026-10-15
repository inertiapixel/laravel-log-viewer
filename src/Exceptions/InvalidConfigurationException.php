<?php

namespace InertiaPixel\LaravelLogViewer\Exceptions;

use InvalidArgumentException;

/**
 * Thrown for configuration that would make the viewer unsafe or broken,
 * such as an invalid redaction pattern. Messages never contain log content.
 */
class InvalidConfigurationException extends InvalidArgumentException
{
}
