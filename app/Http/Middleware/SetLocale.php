<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = null;

        // Priority 1: User preference
        if ($request->user()) {
            $userLocale = $request->user()->getPreference('locale');
            if ($userLocale && in_array($userLocale, static::getSupportedLocales(), true)) {
                $locale = $userLocale;
            }
        }

        // Priority 2: Global DB setting
        if (! $locale) {
            try {
                $globalLocale = Setting::get('locale', null);
                if ($globalLocale && in_array($globalLocale, static::getSupportedLocales(), true)) {
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

    /**
     * The interface locale codes — config('orca.ui_locales'), localization.md REQ-2.
     */
    public static function getSupportedLocales(): array
    {
        return array_keys(config('orca.ui_locales'));
    }
}
