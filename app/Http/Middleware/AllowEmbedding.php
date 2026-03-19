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
}
