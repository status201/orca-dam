<?php

use App\Http\Middleware\AllowEmbedding;
use App\Http\Middleware\AuthenticateMultiple;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Support\DatabaseError;
use App\Support\DatabaseErrorResponder;
use App\Support\ErrorContext;
use App\Support\ErrorResponseDecorator;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

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
            // After AllowEmbedding so it can still relax X-Frame-Options into a
            // frame-ancestors CSP on the response when embedding is enabled.
            SecurityHeaders::class,
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
        // A driver rejection is usually a user error wearing a server error's clothes: a value wider
        // than its column, a name already taken, a required field left empty. Handled here it becomes
        // the validation error that should have caught it, instead of the bare 500 it used to be.
        // ADR-010 still governs *service* failures; see ADR-016 for why this one is global, and
        // specs/features/error-handling.md for the contract.

        // Every log line carries the reference the user can quote, plus (for a QueryException) the DB
        // facts we never show anyone.
        $exceptions->context(fn (Throwable $e): array => ErrorContext::for($e));

        $exceptions->render(fn (QueryException $e, Request $request): ?Response => app(DatabaseErrorResponder::class)->respond($e, $request));

        // Replace the default report line for this one type. The default logs $e->getMessage(),
        // which appends the SQL with the bindings substituted in — user data, in a log that
        // /system's viewer renders. `false` suppresses it; the context callback above supplies the
        // safe equivalent, and passing the exception keeps Monolog's stack trace.
        $exceptions->report(function (QueryException $e): bool {
            Log::error('Database rejected a statement: '.DatabaseError::driverMessage($e), ErrorContext::for($e) + ['exception' => $e]);

            return false;
        });

        // SINGLE-SLOT registration: a second $exceptions->respond() anywhere silently replaces this
        // one and the error reference vanishes from every response.
        $exceptions->respond(fn (Response $response, Throwable $e, Request $request): Response => ErrorResponseDecorator::decorate($response, $request));
    })->create();
