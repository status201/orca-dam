<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;

/**
 * A route `->missing()` handler for form actions whose model is already gone — a second click,
 * a refresh. The browser lands where the first request did instead of on a 404; a fetch client
 * (`expectsJson()`) keeps the 404 it can act on.
 *
 * @see specs/features/asset-trash.md REQ-repeat-trash-redirects
 */
final class RedirectWhenGone
{
    public static function to(string $route): Closure
    {
        return static fn (Request $request) => $request->expectsJson()
            ? abort(404)
            : redirect()->route($route);
    }
}
