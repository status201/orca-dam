<?php

namespace Tests\Security\Support;

use App\Http\Middleware\AuthenticateMultiple;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Read-only view of the application's route table, for the invariants in
 * specs/features/security-invariants.md that derive their coverage from it.
 *
 * Middleware is always reported *resolved* — aliases and group names expanded to class names,
 * the same way `route:list` does it. That matters: `Route::gatherMiddleware()` returns whatever
 * was declared ('web', 'auth'), so a test that string-matches the raw values would miss a route
 * that names a middleware class directly, and would never see inside the `web` group.
 */
class RouteInventory
{
    /**
     * Every routable method/URI pair, keyed "METHOD /uri".
     *
     * HEAD and OPTIONS are dropped — the router synthesises them alongside GET with the same
     * middleware, so keeping them would double every finding.
     *
     * @return array<string, RoutingRoute>
     */
    public static function all(): array
    {
        $inventory = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $inventory[$method.' /'.ltrim($route->uri(), '/')] = $route;
            }
        }

        return $inventory;
    }

    /** @return list<string> */
    public static function middlewareFor(RoutingRoute $route): array
    {
        return app('router')->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());
    }

    /** True when $middleware contains $class, with or without a trailing ":params" list. */
    public static function hasMiddleware(array $middleware, string $class): bool
    {
        foreach ($middleware as $entry) {
            if ($entry === $class || str_starts_with($entry, $class.':')) {
                return true;
            }
        }

        return false;
    }

    public static function requiresAuth(array $middleware): bool
    {
        return self::hasMiddleware($middleware, Authenticate::class)
            || self::hasMiddleware($middleware, AuthenticateMultiple::class);
    }

    public static function isGuestOnly(array $middleware): bool
    {
        return self::hasMiddleware($middleware, RedirectIfAuthenticated::class);
    }

    public static function hasThrottle(array $middleware): bool
    {
        return self::hasMiddleware($middleware, ThrottleRequests::class);
    }

    /** True when the route carries a `can:` gate (Laravel's Authorize middleware). */
    public static function hasAuthorizeGate(array $middleware): bool
    {
        return self::hasMiddleware($middleware, Authorize::class);
    }

    /**
     * The controller class behind a route, or null when the action is a closure.
     *
     * Handles both forms Laravel stores: "Class@method", and a bare "Class" for a single-action
     * invokable controller. Missing the invokable form would silently reclassify those routes as
     * closures and drop them out of any controller-level audit — which is how
     * EmailVerificationPromptController and VerifyEmailController first escaped this one.
     *
     * @return class-string|null
     */
    public static function controllerFor(RoutingRoute $route): ?string
    {
        $action = $route->getAction('controller');

        if (! is_string($action) || $action === '') {
            return null;
        }

        $class = str_contains($action, '@') ? explode('@', $action)[0] : $action;

        return class_exists($class) ? $class : null;
    }
}
