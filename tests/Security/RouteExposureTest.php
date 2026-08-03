<?php

use App\Http\Middleware\AuthenticateMultiple;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;
use Tests\Security\Support\RouteInventory;

/**
 * REQ-1 of specs/features/security-invariants.md — the route table audits itself.
 *
 * The registration incident was not a bug in a route; it was a route nobody knew to test.
 * Breeze's /register shipped mounted but unlinked, and every existing access test named the
 * route it covered, so a green suite said nothing about it. These tests enumerate
 * `Route::getRoutes()` instead: the *coverage* comes from the router, and only the
 * *exceptions* are written down. Mount something unguarded and this file fails until a human
 * adds it to an allowlist below with a reason — which is the review checkpoint that was
 * missing.
 */

// ─── the allowlists — the only hand-maintained lists in this file ──────────────

/**
 * Routes that are deliberately reachable with no credentials at all.
 *
 * Every entry needs a reason. If you are adding one, that reason is the thing a reviewer is
 * being asked to accept.
 *
 * @return array<string, string>
 */
function securityPublicRoutes(): array
{
    return [
        'GET /' => 'Redirect only — sends the caller to assets.index, which is auth-guarded.',
        'GET /csrf-token' => 'Returns a CSRF token for the current session and keeps it alive. Reveals nothing about a session it did not already belong to.',
        'GET /up' => 'Laravel health endpoint mounted by withRouting(health: "/up"); reports liveness only.',
        'GET /sanctum/csrf-cookie' => 'Sanctum SPA cookie endpoint; sets a CSRF cookie, reads nothing.',
        'GET /api/health' => 'Public health check, throttled 60/min. See specs/features/rest-api.md REQ-5.',
        'GET /api/assets/meta' => 'Public asset-metadata lookup by URL for the RTE integration, throttled 60/min, and additionally killable at runtime via the api_meta_endpoint_enabled setting. See specs/features/rest-api.md REQ-5 and RuntimeExposureTogglesTest.',
        // config/filesystems.php sets 'serve' => true on the local disk, so Laravel's
        // FilesystemServiceProvider mounts both verbs. Both handlers open with
        // abort_unless($this->hasValidSignature($request), 404), so they are signed-URL-only.
        'GET /storage/{path}' => 'Framework-mounted local-disk file server; signed-URL-only (ServeFile aborts 404 on an invalid signature).',
        'PUT /storage/{path}' => 'Framework-mounted local-disk upload; signed-URL-only (ReceiveFile aborts 404 on an invalid signature).',
    ];
}

/**
 * Public or guest routes whose rate limiting lives in the handler rather than in route
 * middleware, plus the handful that perform no work worth limiting.
 *
 * @return array<string, string>
 */
function securityRateLimitExemptRoutes(): array
{
    return [
        'POST /login' => 'Rate limited inside LoginRequest::ensureIsNotRateLimited() — 5 attempts per email|ip.',
        'POST /passkey/login' => 'Rate limited inside PasskeyLoginController via RateLimiter, 10/min per IP.',
        'GET /login' => 'Renders a form; no work performed.',
        'GET /passkey/options' => 'Issues WebAuthn challenge options; the assertion itself is limited at POST /passkey/login.',
        'GET /two-factor-challenge' => 'Renders a form; no work performed.',
        'POST /two-factor-challenge' => 'Rate limited inside TwoFactorAuthController — 5 attempts keyed on the pending user id.',
        'GET /' => 'Redirect only.',
        'GET /csrf-token' => 'Returns a token from the existing session; no work performed.',
        'GET /up' => 'Framework health endpoint.',
        'GET /sanctum/csrf-cookie' => 'Sets a cookie; no work performed.',
        'GET /storage/{path}' => 'Signed-URL-only framework route.',
        'PUT /storage/{path}' => 'Signed-URL-only framework route.',
    ];
}

/** The guards `auth.multi` is allowed to be pointed at. */
function securityKnownGuards(): array
{
    return ['web', 'sanctum', 'jwt'];
}

/**
 * Every mounted route that neither requires authentication, nor is a guest-only form, nor
 * appears in the public allowlist. This is the audit itself, factored out so the canary test
 * below can prove it still catches something.
 *
 * @return list<string>
 */
function securityUnguardedRoutes(): array
{
    $public = securityPublicRoutes();
    $unguarded = [];

    foreach (RouteInventory::all() as $key => $route) {
        $middleware = RouteInventory::middlewareFor($route);

        if (RouteInventory::requiresAuth($middleware) || RouteInventory::isGuestOnly($middleware)) {
            continue;
        }

        if (array_key_exists($key, $public)) {
            continue;
        }

        $unguarded[] = $key.'  ('.$route->getActionName().')';
    }

    return $unguarded;
}

// ─── REQ-1: nothing is reachable unless it says so ────────────────────────────

test('every route is authenticated, guest-only, or an allowlisted public endpoint', function () {
    $unguarded = securityUnguardedRoutes();

    expect($unguarded)->toBe([],
        "These routes are reachable with no credentials and are not in securityPublicRoutes():\n  "
        .implode("\n  ", $unguarded)
        ."\n\nAn unlinked route is not an unreachable one — that is exactly how the /register hole "
        .'stayed open (specs/features/authentication.md REQ-8). Either guard it, or add it to the '
        .'allowlist in this file with a reason a reviewer would accept.'
    );
});

/**
 * The audit above is only worth its green tick if it can go red. An invariant that has
 * silently stopped matching reads exactly like a clean codebase — which is the failure mode
 * this whole suite exists to prevent — so the mutation is performed here rather than left to
 * a checklist someone runs by hand. Mount an unguarded route and the audit must name it.
 */
test('the exposure audit actually catches an unguarded route', function () {
    expect(securityUnguardedRoutes())->toBe([]);

    Route::get('security-canary-unguarded', fn () => 'reachable');

    expect(securityUnguardedRoutes())
        ->toHaveCount(1)
        ->and(securityUnguardedRoutes()[0])->toContain('GET /security-canary-unguarded');
});

test('the public-route allowlist has no stale entries', function () {
    $inventory = RouteInventory::all();

    $stale = array_values(array_diff(array_keys(securityPublicRoutes()), array_keys($inventory)));

    expect($stale)->toBe([],
        'These securityPublicRoutes() entries no longer match a mounted route: '.implode(', ', $stale)
        .'. A stale allowlist silently pre-approves whatever is mounted at that path next.'
    );
});

/**
 * Middleware introspection proves what is *declared*. It cannot prove the stack is ordered
 * correctly, or that a controller does not short-circuit it. A real unauthenticated request
 * can. Restricted to parameter-less GETs so no fixtures are needed; the introspection test
 * above covers every other verb and every parameterised URI.
 */
test('an unauthenticated GET of a guarded page never succeeds', function () {
    $public = securityPublicRoutes();
    $leaked = [];

    foreach (RouteInventory::all() as $key => $route) {
        if (! str_starts_with($key, 'GET ')) {
            continue;
        }

        if (str_contains($route->uri(), '{') || array_key_exists($key, $public)) {
            continue;
        }

        $middleware = RouteInventory::middlewareFor($route);

        if (RouteInventory::isGuestOnly($middleware)) {
            continue; // a guest form is supposed to render for a guest
        }

        $status = $this->get('/'.ltrim($route->uri(), '/'))->getStatusCode();

        if ($status < 300) {
            $leaked[] = $key.' returned '.$status;
        }
    }

    expect($leaked)->toBe([],
        "These guarded pages served a 2xx to a caller with no session:\n  ".implode("\n  ", $leaked)
    );
});

test('every unauthenticated route is rate limited', function () {
    $exempt = securityRateLimitExemptRoutes();
    $unlimited = [];

    foreach (RouteInventory::all() as $key => $route) {
        $middleware = RouteInventory::middlewareFor($route);

        if (RouteInventory::requiresAuth($middleware)) {
            continue;
        }

        if (RouteInventory::hasThrottle($middleware) || array_key_exists($key, $exempt)) {
            continue;
        }

        $unlimited[] = $key;
    }

    expect($unlimited)->toBe([],
        "These routes are reachable without authentication and carry no throttle:\n  "
        .implode("\n  ", $unlimited)
        ."\n\nAn unauthenticated endpoint with no limit is an enumeration and DoS surface. Add "
        .'throttle middleware, or add it to securityRateLimitExemptRoutes() naming where the '
        .'limiting actually happens.'
    );
});

// ─── REQ-1: the registration surface stays gone, under any name ───────────────

/**
 * tests/Feature/Auth/RegistrationTest.php pins the exact URL that was exploited. This pins
 * the *shape*, so a re-mount under any other name (/signup, /users/register, a named route
 * "account.register") fails too.
 */
test('no route exposes a self-service registration surface', function () {
    $suspicious = [];

    foreach (RouteInventory::all() as $key => $route) {
        $name = $route->getName() ?? '';

        if (preg_match('/regist|signup|sign-up/i', $route->uri().' '.$name)) {
            $suspicious[] = $key.($name !== '' ? ' (name: '.$name.')' : '');
        }
    }

    expect($suspicious)->toBe([],
        "These routes look like a registration surface:\n  ".implode("\n  ", $suspicious)
        ."\n\nORCA provisions accounts through /users (admin-only) or token:create. See "
        .'specs/features/authentication.md REQ-8 — self-service signup granted editor to anyone.'
    );
});

// ─── REQ-1: the guard story stays the one architecture.md documents ───────────

test('auth.multi is only ever pointed at known guards', function () {
    $unknown = [];

    foreach (RouteInventory::all() as $key => $route) {
        foreach (RouteInventory::middlewareFor($route) as $entry) {
            if (! str_starts_with($entry, AuthenticateMultiple::class.':')) {
                continue;
            }

            $guards = explode(',', substr($entry, strlen(AuthenticateMultiple::class) + 1));

            foreach ($guards as $guard) {
                if (! in_array($guard, securityKnownGuards(), true)) {
                    $unknown[] = $key.' → '.$guard;
                }
            }
        }
    }

    expect($unknown)->toBe([],
        'These routes name a guard outside the documented set ('.implode(', ', securityKnownGuards())
        .'): '.implode(', ', $unknown).'. See specs/architecture.md and ADR-004.'
    );
});

test('no route bypasses auth.multi with a bare guard-specific auth middleware', function () {
    $direct = [];

    foreach (RouteInventory::all() as $key => $route) {
        foreach (RouteInventory::middlewareFor($route) as $entry) {
            if (str_starts_with($entry, Authenticate::class.':')) {
                $direct[] = $key.' → '.$entry;
            }
        }
    }

    expect($direct)->toBe([],
        'These routes pin a specific guard through Laravel\'s auth middleware instead of the '
        .'session default or auth.multi: '.implode(', ', $direct)
        .'. ADR-004 puts every non-session mechanism behind AuthenticateMultiple so the '
        .'resolution order stays in one place.'
    );
});

// ─── scanner self-check ───────────────────────────────────────────────────────

/**
 * If the inventory ever comes back empty or tiny, every test above passes vacuously. That is
 * the failure mode this suite exists to prevent, so it gets its own assertion.
 */
test('the route inventory sees the whole application', function () {
    $inventory = RouteInventory::all();

    expect(count($inventory))->toBeGreaterThan(100);

    // A guarded page, a guest form, a public endpoint and an API route — one of each shape,
    // so a change that silently stops enumerating one of them is caught.
    expect($inventory)->toHaveKeys(['GET /assets', 'GET /login', 'GET /api/health', 'DELETE /api/assets/{asset}']);
});
