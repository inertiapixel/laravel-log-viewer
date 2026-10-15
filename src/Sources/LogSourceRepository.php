<?php

namespace InertiaPixel\LaravelLogViewer\Sources;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use InertiaPixel\LaravelLogViewer\Exceptions\InvalidConfigurationException;
use InertiaPixel\LaravelLogViewer\Support\PathGuard;
use InertiaPixel\LaravelLogViewer\Support\Settings;

/**
 * Discovers the allowlisted log files.
 *
 * Files are looked up by opaque id only; request input is never turned
 * into a filesystem path. Discovery results are memoized per request.
 */
final class LogSourceRepository
{
    /** @var array<string, LogFile>|null */
    private ?array $files = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly Repository $config,
        private readonly Container $container,
        private readonly string $basePath,
    ) {
    }

    /**
     * Allowlisted files, most recently modified first.
     *
     * @return array<string, LogFile> keyed by id
     */
    public function all(): array
    {
        return $this->files ??= $this->discover();
    }

    public function find(?string $id): ?LogFile
    {
        if (! is_string($id) || ! preg_match('/^[a-f0-9]{16}$/', $id)) {
            return null;
        }

        return $this->all()[$id] ?? null;
    }

    /**
     * @return array<string, LogFile>
     */
    private function discover(): array
    {
        $limit = $this->settings->int('sources.max_files', 1, 10000);
        $found = [];

        foreach ($this->roots() as $root) {
            foreach ($this->scan($root['path'], $root['patterns'], $root['depth']) as $path) {
                $found[$path] ??= $root['path'];
            }
        }

        foreach ($this->explicitFiles() as $path) {
            $found[$path] ??= dirname($path);
        }

        $files = [];

        foreach ($found as $path => $location) {
            $stat = @stat($path);

            if ($stat === false) {
                continue;
            }

            $file = new LogFile(
                LogFile::idFor($path),
                PathGuard::relative($path, $location),
                $path,
                $this->label($location),
                (int) $stat['size'],
                (int) $stat['mtime'],
                is_readable($path),
            );

            $files[$file->id] = $file;
        }

        uasort($files, fn (LogFile $a, LogFile $b) => [$b->modifiedAt, $a->name] <=> [$a->modifiedAt, $b->name]);

        return array_slice($files, 0, $limit, true);
    }

    /**
     * @return list<array{path: string, patterns: list<string>, depth: int}>
     */
    private function roots(): array
    {
        $patterns = $this->settings->strings('sources.patterns');
        $depth = $this->settings->bool('sources.recursive') ? $this->settings->int('sources.max_depth', 0, 10) : 0;
        $roots = [];

        if ($patterns !== []) {
            foreach ($this->settings->array('sources.directories') as $directory) {
                if (($path = PathGuard::directory($directory)) !== null) {
                    $roots[] = ['path' => $path, 'patterns' => $patterns, 'depth' => $depth];
                }
            }
        }

        // Daily channels write "laravel-YYYY-MM-DD.log" next to the configured path.
        foreach ($this->loggingChannelPaths('daily') as $path) {
            if (($directory = PathGuard::directory(dirname($path))) !== null) {
                $name = pathinfo($path, PATHINFO_FILENAME);
                $extension = pathinfo($path, PATHINFO_EXTENSION);
                $suffix = $extension === '' ? '' : '.'.$extension;
                $roots[] = ['path' => $directory, 'patterns' => [$name.$suffix, $name.'-*'.$suffix], 'depth' => 0];
            }
        }

        return $roots;
    }

    /**
     * Canonical paths of individually allowlisted files.
     *
     * @return list<string>
     */
    private function explicitFiles(): array
    {
        $candidates = array_merge($this->settings->array('sources.files'), $this->loggingChannelPaths('single'));

        $resolver = $this->settings->get('sources.resolver');

        if ($resolver !== null) {
            $instance = is_string($resolver) ? $this->container->make($resolver) : $resolver;

            if (! $instance instanceof LogPathResolver) {
                throw new InvalidConfigurationException('log-viewer.sources.resolver must implement '.LogPathResolver::class.'.');
            }

            foreach ($instance->paths() as $path) {
                $candidates[] = $path;
            }
        }

        $paths = [];

        foreach ($candidates as $candidate) {
            if (($path = PathGuard::file($candidate)) !== null) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function loggingChannelPaths(string $driver): array
    {
        if (! $this->settings->bool('sources.include_logging_channels')) {
            return [];
        }

        $paths = [];

        foreach ((array) $this->config->get('logging.channels', []) as $channel) {
            if (is_array($channel) && ($channel['driver'] ?? null) === $driver && is_string($channel['path'] ?? null)) {
                $paths[] = $channel['path'];
            }
        }

        return $paths;
    }

    /**
     * Matching regular files below a canonical directory. Hidden entries
     * are skipped and every match must canonicalize back inside the root,
     * which rejects symlinks pointing elsewhere.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    private function scan(string $root, array $patterns, int $depth, string $relative = ''): array
    {
        $directory = $relative === '' ? $root : $root.DIRECTORY_SEPARATOR.$relative;
        $entries = @scandir($directory);

        if ($entries === false) {
            return [];
        }

        $matches = [];

        foreach ($entries as $entry) {
            if ($entry === '' || $entry[0] === '.') {
                continue;
            }

            $child = $relative === '' ? $entry : $relative.DIRECTORY_SEPARATOR.$entry;
            $full = $root.DIRECTORY_SEPARATOR.$child;

            if (is_dir($full)) {
                if ($depth > 0) {
                    array_push($matches, ...$this->scan($root, $patterns, $depth - 1, $child));
                }

                continue;
            }

            if (! $this->matches($entry, $patterns)) {
                continue;
            }

            $real = PathGuard::file($full);

            if ($real !== null && PathGuard::isWithin($real, $root) && ! PathGuard::isHidden(PathGuard::relative($real, $root))) {
                $matches[] = $real;
            }
        }

        return $matches;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matches(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $name, FNM_PERIOD)) {
                return true;
            }
        }

        return false;
    }

    /** Human label for a directory, relative to the application when possible. */
    private function label(string $directory): string
    {
        $base = rtrim($this->basePath, '/\\');

        if ($directory === $base) {
            return '.';
        }

        if (PathGuard::isWithin($directory, $base)) {
            return str_replace('\\', '/', PathGuard::relative($directory, $base));
        }

        return basename($directory);
    }
}
