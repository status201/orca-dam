<?php

namespace App\Providers;

use App\Auth\JwtGuard;
use App\Demos\DemoRegistry;
use App\Demos\WelcomeDemo;
use App\Http\Controllers\SystemController;
use App\Listeners\EnforcePasskeyLimit;
use App\Listeners\TouchPasskeyLastUsed;
use App\Models\Passkey;
use App\Models\Setting;
use App\Models\User;
use App\Observers\UserObserver;
use App\Policies\SystemPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ORCA owns the passkey route URLs (see routes/auth.php) — the package's
        // /passkeys/* defaults are disabled so we can keep /passkey/login etc.
        Passkeys::ignoreRoutes();

        // Encrypt the serialized credential blob at rest.
        Passkeys::usePasskeyModel(Passkey::class);

        // Guided demos are declared in PHP and registered explicitly — the list order
        // is the order they are offered in. See specs/recipes/add-a-guided-demo.md.
        $this->app->singleton(DemoRegistry::class, fn () => new DemoRegistry([
            new WelcomeDemo,
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Apply timezone from database setting
        try {
            $timezone = Setting::get('timezone', config('app.timezone'));
            if (in_array($timezone, timezone_identifiers_list())) {
                date_default_timezone_set($timezone);
                config(['app.timezone' => $timezone]);
            }
        } catch (\Throwable $e) {
            // Fall back to config default if database is unavailable
        }

        // Register SystemController policy
        Gate::policy(SystemController::class, SystemPolicy::class);

        // Register the `mail::` view namespace so custom mail Blades (e.g. emails/reset-password.blade.php)
        // can use `<x-mail::message>`, `<x-mail::header>`, `<x-mail::button>` etc. Laravel only registers
        // this namespace transiently during markdown render, not for plain `view()` mailables.
        View::addNamespace('mail', [
            base_path('vendor/laravel/framework/src/Illuminate/Mail/resources/views/html'),
        ]);

        // Register JWT guard driver for API authentication
        Auth::extend('jwt', function ($app, $name, array $config) {
            return new JwtGuard(
                Auth::createUserProvider($config['provider'])
            );
        });

        // Stamp last_passkey_used_at on successful passkey assertions.
        Event::listen(PasskeyVerified::class, TouchPasskeyLastUsed::class);

        // Defense-in-depth: enforce per-user passkey cap after registration.
        Event::listen(PasskeyRegistered::class, EnforcePasskeyLimit::class);

        // Append-only trail of user create / re-role / delete — an UPDATE that flips
        // `role` otherwise leaves no trace. See specs/features/user-audit-log.md.
        User::observe(UserObserver::class);

        // `artisan serve --env=X` passes through only an allowlist of environment
        // variables and explicitly unsets the rest in the server process. The temp
        // directory is not on that list, so on Windows PHP's GetTempPath() falls
        // through to the Windows directory — unwritable — and every multipart
        // request dies at request startup with "unable to create a temporary file",
        // surfacing as a 422 with no exception and nothing in the log. Harmless on
        // Linux, where PHP falls back to /tmp. See specs/features/e2e-testing.md REQ-1.
        ServeCommand::$passthroughVariables = array_values(array_unique(array_merge(
            ServeCommand::$passthroughVariables,
            ['TMP', 'TEMP', 'TMPDIR'],
        )));

        $this->configureRateLimiting();
    }

    /**
     * Named limiters for the heavy web routes. A bare `throttle:N,1` keys its counter on the
     * user id alone, so every such route would share one per-user budget — naming each gives it
     * its own. See specs/features/upload-policy.md REQ-7.
     */
    private function configureRateLimiting(): void
    {
        $perUser = fn (Request $request): string => (string) ($request->user()?->id ?: $request->ip());

        RateLimiter::for('bulk-download', fn (Request $request) => Limit::perMinute(20)->by($perUser($request)));
        RateLimiter::for('ai-tag', fn (Request $request) => Limit::perMinute(30)->by($perUser($request)));
        RateLimiter::for('chunked-upload', fn (Request $request) => Limit::perMinute(100)->by($perUser($request)));

        // Read per request, not at boot, so the limit follows config (and tests can override it).
        RateLimiter::for('tikz-render', fn (Request $request) => Limit::perMinute((int) config('tikz.render_rate_limit', 60))->by($perUser($request)));
    }
}
