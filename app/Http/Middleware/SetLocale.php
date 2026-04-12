<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    protected static array $supportedLocales = ['en', 'nl'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = null;

        // Priority 1: User preference
        if ($request->user()) {
            $userLocale = $request->user()->getPreference('locale');
            if ($userLocale && in_array($userLocale, static::$supportedLocales)) {
                $locale = $userLocale;
            }
        }

        // Priority 2: Global DB setting
        if (! $locale) {
            try {
                $globalLocale = Setting::get('locale', null);
                if ($globalLocale && in_array($globalLocale, static::$supportedLocales)) {
                    $locale = $globalLocale;
                }
            } catch (\Throwable $e) {
                // Fall through to config default
            }
        }

        // Priority 3: Config fallback
        if (! $locale) {
            $locale = config('app.locale', 'en');
        }

        App::setLocale($locale);

        return $next($request);
    }

    public static function getSupportedLocales(): array
    {
        return static::$supportedLocales;
    }
}
