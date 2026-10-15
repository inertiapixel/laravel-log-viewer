<?php

namespace InertiaPixel\LaravelLogViewer\Support;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Facades\Vite;

/**
 * URLs and tags for the bundled stylesheet and script.
 *
 * Assets ship precompiled in resources/dist, so the host application needs
 * no npm or Vite setup. They are served by a package route, or from
 * public/ after `vendor:publish --tag=inertiapixel-log-viewer-assets`.
 */
final class AssetManager
{
    public const PUBLISH_PATH = 'vendor/inertiapixel/log-viewer';

    /** Public asset name => file in resources/dist. */
    public const FILES = [
        'log-viewer.js' => 'inertiapixel-log-viewer.js',
        'log-viewer.css' => 'inertiapixel-log-viewer.css',
    ];

    /** @var array<string, string> */
    private static array $versions = [];

    public function __construct(private readonly Settings $settings, private readonly UrlGenerator $url)
    {
    }

    public static function distPath(string $asset): string
    {
        return dirname(__DIR__, 2).'/resources/dist/'.self::FILES[$asset];
    }

    public static function version(string $asset): string
    {
        $path = self::distPath($asset);

        return self::$versions[$asset] ??= is_file($path) ? substr((string) md5_file($path), 0, 12) : 'missing';
    }

    public function url(string $asset): string
    {
        $version = self::version($asset);

        if ($this->settings->string('assets.mode', 'route') === 'published') {
            $base = $this->settings->string('assets.url');
            $url = $base !== '' ? rtrim($base, '/').'/'.self::FILES[$asset] : $this->url->asset(self::PUBLISH_PATH.'/'.self::FILES[$asset]);

            return $url.'?v='.$version;
        }

        return $this->url->route($this->settings->string('route.name').'asset', ['file' => $asset, 'v' => $version]);
    }

    public function styles(): string
    {
        return '<link rel="stylesheet" href="'.e($this->url('log-viewer.css')).'" data-ipx-log-viewer-asset="css"'.$this->nonce().'>';
    }

    public function scripts(): string
    {
        return '<script src="'.e($this->url('log-viewer.js')).'" defer data-ipx-log-viewer-asset="js"'.$this->nonce().'></script>';
    }

    private function nonce(): string
    {
        $nonce = class_exists(Vite::class) ? Vite::cspNonce() : null;

        return is_string($nonce) && $nonce !== '' ? ' nonce="'.e($nonce).'"' : '';
    }
}
