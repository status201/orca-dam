<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AllowEmbedding
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $domains = Setting::get('embed_allowed_domains', []);

            if (is_string($domains)) {
                $domains = json_decode($domains, true) ?? [];
            }

            // Only allow well-formed host/origin tokens into the CSP directive,
            // so a malformed setting can't inject extra CSP directives.
            $domains = array_values(array_filter(
                array_map('trim', (array) $domains),
                fn ($domain) => is_string($domain) && self::isValidAncestor($domain)
            ));

            if (! empty($domains)) {
                $ancestors = "'self' ".implode(' ', $domains);
                $response->headers->set('Content-Security-Policy', "frame-ancestors {$ancestors}");
                $response->headers->remove('X-Frame-Options');
            }
        } catch (\Throwable $e) {
            // If settings table doesn't exist yet (e.g. during migrations), skip
        }

        return $response;
    }

    /**
     * Validate a single frame-ancestors source. Accepts optional scheme, a
     * hostname (with optional leading wildcard label and optional port), and
     * rejects anything containing whitespace, quotes, semicolons, or other
     * characters that could break out of the directive.
     */
    private static function isValidAncestor(string $domain): bool
    {
        if ($domain === '' || preg_match('/[\s;,\'"]/', $domain)) {
            return false;
        }

        return (bool) preg_match(
            '#^(https?://)?(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:\d{1,5})?$#i',
            $domain
        );
    }
}
