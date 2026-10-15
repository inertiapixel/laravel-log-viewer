<?php

namespace InertiaPixel\LaravelLogViewer\Tests\Concerns;

use Illuminate\Filesystem\Filesystem;

trait CreatesLogFiles
{
    private ?string $logDirectory = null;

    protected function logDirectory(): string
    {
        if ($this->logDirectory === null) {
            $this->logDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ipx-log-viewer-'.bin2hex(random_bytes(6));
            mkdir($this->logDirectory, 0777, true);
            // Canonical form (macOS /var -> /private/var).
            $this->logDirectory = (string) realpath($this->logDirectory);
        }

        return $this->logDirectory;
    }

    protected function writeLog(string $name, string $contents, ?int $modifiedAt = null): string
    {
        $path = $this->logDirectory().DIRECTORY_SEPARATOR.$name;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);

        if ($modifiedAt !== null) {
            touch($path, $modifiedAt);
        }

        clearstatcache();

        return $path;
    }

    /**
     * One Laravel-formatted line: "[Y-m-d H:i:s] channel.LEVEL: message".
     */
    protected function line(string $datetime, string $level, string $message, string $channel = 'local'): string
    {
        return "[{$datetime}] {$channel}.".strtoupper($level).": {$message}\n";
    }

    protected function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__).'/fixtures/'.$name);
    }

    protected function removeLogDirectory(): void
    {
        if ($this->logDirectory !== null && is_dir($this->logDirectory)) {
            (new Filesystem())->deleteDirectory($this->logDirectory);
        }

        $this->logDirectory = null;
    }
}
