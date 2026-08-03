<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GameScoreController;
use App\Http\Controllers\GuidedDemoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\ToolsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Tests\Security\Support\RouteInventory;
use Tests\Security\Support\UngatedCanaryController;

/**
 * REQ-3 of specs/features/security-invariants.md — every authenticated endpoint is gated
 * somewhere, and the "somewhere" is named.
 *
 * `auth` middleware only proves a caller is *somebody*. What stops an editor from reaching
 * /users is either a `can:` gate on the route or an `authorize()` call in the controller — and
 * ORCA uses both, inconsistently. `system/*` is gated at the route; `Route::resource('users')`
 * relies entirely on seven `$this->authorize()` calls inside UserController. Neither is wrong,
 * but a *new* method added to an ungated controller inherits nothing, and no existing test
 * would notice.
 *
 * So: every controller behind an authenticated route must gate its routes, gate itself, or be
 * listed below as deliberately open to all authenticated roles.
 */

/**
 * Controllers whose actions are intentionally available to every authenticated role, so they
 * carry no authorization check of their own.
 *
 * Each entry is a claim that "any logged-in user may do this", which is exactly what a reviewer
 * should be made to agree with before a controller lands here.
 *
 * @return array<class-string, string>
 */
function controllersOpenToAllRoles(): array
{
    return [
        AboutController::class => 'Static about page; no data beyond app version info.',
        DashboardController::class => 'Post-login landing page. Self-scoped stats plus a tour built from the caller\'s own role.',
        ProfileController::class => 'Self-scoped by construction — every action operates on $request->user(). Role changes are impossible here because ProfileUpdateRequest only permits name and email (pinned by ModelInvariantsTest).',
        GameScoreController::class => 'Easter-egg leaderboard; submits and lists the caller\'s own scores.',
        GuidedDemoController::class => 'Records that the caller finished a demo. Self-scoped; eligibility lives on the demo definition. See specs/features/guided-demos.md.',
        // AssetPolicy grants view/create/update to all three roles (admin, editor, api), so
        // these two are open by the matrix rather than by omission. Confirmed against
        // PolicyMatrixTest: 'AssetPolicy::create' => all roles.
        TagController::class => 'Tag read/write. AssetPolicy grants create/update to all three roles, so tag management is not a privilege boundary.',
        ToolsController::class => 'Client-side asset-authoring tools (TikZ, GIF, MathML). Uploads go through ToolUploadRequest::authorize(), which delegates to AssetPolicy::create — granted to all three roles.',

        // Breeze account-management controllers. Every action operates on the caller's own
        // credentials or session, so there is no privilege boundary to gate: any authenticated
        // user may log out, change their own password, or re-request their own verification
        // mail. TwoFactorAuthController and PasskeyController are *not* here — they gate the
        // api role out via canEnableTwoFactor()/canEnablePasskeys() and are detected as such.
        AuthenticatedSessionController::class => 'Login and logout. The authenticated route is POST /logout, which ends the caller\'s own session.',
        PasswordController::class => 'Changes the caller\'s own password; requires the current password.',
        ConfirmablePasswordController::class => 'Re-confirms the caller\'s own password to unlock the password.confirm middleware.',
        EmailVerificationNotificationController::class => 'Re-sends the caller\'s own verification mail; throttled 6/min.',
        EmailVerificationPromptController::class => 'Shows the caller their own verification prompt.',
        VerifyEmailController::class => 'Consumes a signed verification link for the caller\'s own account; the route carries `signed` and throttle:6,1.',
    ];
}

/**
 * Closure-backed authenticated routes, which have no controller to scan.
 *
 * @return array<string, string>
 */
function closureRoutesOpenToAllRoles(): array
{
    return [
        'POST /locale' => 'Writes the caller\'s own locale preference after validating it against the en/nl allowlist.',
    ];
}

/** Calls that constitute an actual authorization decision. */
function authorizationCallPatterns(): array
{
    return [
        '$this->authorize(',
        'authorizeResource(',
        'Gate::',
        '->can(',
        '->cannot(',
        '->denies(',
        '->allows(',
        'abort_unless(',
        'abort_if(',
        // Role gates that predate the policies and live on the User model. Unlike isAdmin(),
        // these two exist only to authorize — they are the api-role exclusion for 2FA and
        // passkey enrolment (specs/features/two-factor-auth.md REQ-1, passkeys.md REQ-1).
        '->canEnableTwoFactor(',
        '->canEnablePasskeys(',
    ];
}

/**
 * True when $class's source performs at least one authorization decision.
 *
 * Deliberately does *not* count `isAdmin()` / `isEditor()` / `isApiUser()` on their own: those
 * read the role for presentation as often as for gating (a dashboard branching on role is not
 * an authorization check), and accepting them would let a controller pass while deciding
 * nothing.
 */
function controllerGatesItself(string $class): bool
{
    $file = (new ReflectionClass($class))->getFileName();

    if ($file === false) {
        return false;
    }

    $source = file_get_contents($file);

    foreach (authorizationCallPatterns() as $pattern) {
        if (str_contains($source, $pattern)) {
            return true;
        }
    }

    return false;
}

/**
 * Authenticated routes grouped by the controller behind them, closures keyed by route.
 *
 * @return array{controllers: array<class-string, list<string>>, closures: list<string>}
 */
function authenticatedActionMap(): array
{
    $controllers = [];
    $closures = [];

    foreach (RouteInventory::all() as $key => $route) {
        $middleware = RouteInventory::middlewareFor($route);

        if (! RouteInventory::requiresAuth($middleware)) {
            continue;
        }

        $controller = RouteInventory::controllerFor($route);

        if ($controller === null) {
            $closures[] = $key;

            continue;
        }

        $controllers[$controller][] = $key;
    }

    return ['controllers' => $controllers, 'closures' => $closures];
}

/**
 * Controllers behind authenticated routes that gate neither their routes nor themselves, and
 * are not allowlisted. Factored out so the canary below can prove the audit still bites.
 *
 * @return list<string>
 */
function ungatedControllers(): array
{
    $allowed = controllersOpenToAllRoles();
    $ungated = [];

    foreach (authenticatedActionMap()['controllers'] as $controller => $routeKeys) {
        if (array_key_exists($controller, $allowed) || controllerGatesItself($controller)) {
            continue;
        }

        $everyRouteGated = true;

        foreach ($routeKeys as $key) {
            $route = RouteInventory::all()[$key];

            if (! RouteInventory::hasAuthorizeGate(RouteInventory::middlewareFor($route))) {
                $everyRouteGated = false;
                break;
            }
        }

        if (! $everyRouteGated) {
            $ungated[] = $controller.' ('.implode(', ', $routeKeys).')';
        }
    }

    return $ungated;
}

// ─── REQ-3 ────────────────────────────────────────────────────────────────────

test('every controller behind an authenticated route is gated or allowlisted', function () {
    $ungated = ungatedControllers();

    expect($ungated)->toBe([],
        'These controllers are reachable by any authenticated user — including api-role tokens — '
        ."with no authorization decision anywhere:\n  ".implode("\n  ", $ungated)
        ."\n\nGate the routes with `can:`, call \$this->authorize() in the controller, or add the "
        .'class to controllersOpenToAllRoles() with a reason.'
    );
});

/** The audit has to be able to fire. */
test('the controller audit actually catches an ungated controller', function () {
    expect(ungatedControllers())->toBe([]);

    // A real controller class with no authorization call, mounted behind `auth` only.
    Route::middleware('auth')
        ->get('security-canary-ungated', [UngatedCanaryController::class, 'index']);

    $ungated = ungatedControllers();

    expect($ungated)->toHaveCount(1)
        ->and($ungated[0])->toContain('UngatedCanaryController');
});

test('every closure-backed authenticated route is gated or allowlisted', function () {
    $allowed = closureRoutesOpenToAllRoles();
    $ungated = [];

    foreach (authenticatedActionMap()['closures'] as $key) {
        $route = RouteInventory::all()[$key];

        if (RouteInventory::hasAuthorizeGate(RouteInventory::middlewareFor($route))) {
            continue;
        }

        if (array_key_exists($key, $allowed)) {
            continue;
        }

        $ungated[] = $key;
    }

    expect($ungated)->toBe([],
        'These closure routes are reachable by any authenticated user with no authorization '
        .'decision: '.implode(', ', $ungated)
        .'. A closure has no controller to scan, so it must carry a `can:` gate or be '
        .'allowlisted in closureRoutesOpenToAllRoles().'
    );
});

test('the open-to-all-roles allowlist has no stale entries', function () {
    $routed = array_keys(authenticatedActionMap()['controllers']);

    $stale = array_values(array_diff(array_keys(controllersOpenToAllRoles()), $routed));

    expect($stale)->toBe([],
        'These controllersOpenToAllRoles() entries no longer sit behind an authenticated route: '
        .implode(', ', $stale).'. Remove them so the list stays a live statement of intent.'
    );
});

// ─── self-check ───────────────────────────────────────────────────────────────

test('the controller audit sees the whole authenticated surface', function () {
    $map = authenticatedActionMap();

    expect(count($map['controllers']))->toBeGreaterThanOrEqual(20);

    // One gated-at-the-route controller, one gated-in-the-controller, one allowlisted.
    expect($map['controllers'])->toHaveKeys([
        SystemController::class,
        UserController::class,
        ProfileController::class,
    ]);
});

test('the authorization-call detector distinguishes gating from role display', function () {
    // UserController gates itself with $this->authorize(); ProfileController decides nothing.
    expect(controllerGatesItself(UserController::class))->toBeTrue();
    expect(controllerGatesItself(ProfileController::class))->toBeFalse();
    expect(controllerGatesItself(UngatedCanaryController::class))->toBeFalse();
});

/**
 * Not asserted here: that every file under app/Http/Controllers sits behind some route. A
 * controller with no route at all is dead code, not exposure, and RouteExposureTest already
 * answers the reachability question from the router — the authoritative direction. Asserting
 * the reverse only produced a snapshot of the guest-only Auth controllers that needed editing
 * whenever the scaffold moved.
 */
