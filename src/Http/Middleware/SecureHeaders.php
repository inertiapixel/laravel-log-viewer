<?php

namespace InertiaPixel\LaravelLogViewer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Log pages contain sensitive data: never cache them, never let them be
 * framed by other origins or indexed, and never leak their URL (which can
 * contain search terms) in the Referer header.
 */
final class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
