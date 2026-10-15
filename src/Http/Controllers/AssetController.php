<?php

namespace InertiaPixel\LaravelLogViewer\Http\Controllers;

use Illuminate\Http\Request;
use InertiaPixel\LaravelLogViewer\Support\AssetManager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the two bundled assets. Static and public: no session, no auth.
 */
final class AssetController
{
    private const TYPES = [
        'log-viewer.js' => 'application/javascript; charset=utf-8',
        'log-viewer.css' => 'text/css; charset=utf-8',
    ];

    public function __invoke(Request $request, string $file): Response
    {
        abort_unless(isset(AssetManager::FILES[$file]), 404);

        $path = AssetManager::distPath($file);

        abort_unless(is_file($path), 404);

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => self::TYPES[$file],
            'X-Content-Type-Options' => 'nosniff',
        ], true, null, true, true);

        // Versioned URLs never change content, so they can be cached forever.
        if ($request->query('v') === AssetManager::version($file)) {
            $response->setCache(['public' => true, 'max_age' => 31536000, 'immutable' => true]);
        } else {
            $response->setCache(['public' => true, 'max_age' => 300]);
        }

        $response->isNotModified($request);

        return $response;
    }
}
