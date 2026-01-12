<?php

namespace App\Providers;

use App\Http\Controllers\SystemController;
use App\Policies\SystemPolicy;
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
    }
}
