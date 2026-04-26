<?php

namespace App\Providers;

use App\Auth\JwtGuard;
use App\Http\Controllers\SystemController;
use App\Models\Setting;
use App\Policies\SystemPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
    }
}
