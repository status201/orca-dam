<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies baseline HTTP security headers to web responses.
 *
 * Registered AFTER AllowEmbedding in the web group so that, on the response
 * (which unwinds in reverse), AllowEmbedding still runs last and can relax the
 * X-Frame-Options default into a frame-ancestors CSP when embedding is enabled.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        // Stop browsers from MIME-sniffing a response away from its declared type.
        if (! $headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }

        // Baseline clickjacking protection. AllowEmbedding removes this and sets
        // a frame-ancestors CSP instead when embed_allowed_domains is configured.
        if (! $headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        // HSTS only when already served over HTTPS, so we never pin an http-only
        // dev/test setup to https.
        if ($request->isSecure() && ! $headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
