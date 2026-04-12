<?php

use App\Http\Middleware\AllowEmbedding;
use App\Http\Middleware\AuthenticateMultiple;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            AllowEmbedding::class,
        ]);

        $middleware->alias([
            'verified' => EnsureEmailIsVerified::class,
            'auth.multi' => AuthenticateMultiple::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Cleanup stale upload sessions daily
        $schedule->command('uploads:cleanup --hours=24')->daily();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
