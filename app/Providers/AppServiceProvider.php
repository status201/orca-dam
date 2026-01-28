<?php

namespace App\Providers;

use App\Auth\JwtGuard;
use App\Http\Controllers\SystemController;
use App\Policies\SystemPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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
        // Register SystemController policy
        Gate::policy(SystemController::class, SystemPolicy::class);

        // Register JWT guard driver for API authentication
        Auth::extend('jwt', function ($app, $name, array $config) {
            return new JwtGuard(
                Auth::createUserProvider($config['provider']),
                $app['request']
            );
        });
    }
}
