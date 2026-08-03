<?php

use App\Http\Controllers\SystemController;
use App\Models\Asset;
use App\Models\Setting;
use App\Models\User;
use App\Policies\AssetPolicy;
use App\Policies\SystemPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\DB;
use Tests\Security\Support\SourceScanner;

/**
 * REQ-2 of specs/features/security-invariants.md — the policy layer audits itself.
 *
 * tests/Unit/Policies/AssetPolicyTest.php already asserts a role matrix, and it stays: it is
 * the readable per-policy record and it owns specs/features/authorization-policies.md. What it
 * cannot do is notice a *new* ability, because its dataset is hand-written — add
 * `UserPolicy::purge()` and that file still passes.
 *
 * This file discovers abilities by reflection and requires every one of them to appear in the
 * matrix below. A new ability is therefore a failing test until its roles are stated, which is
 * the point: ADR-002 exists because an ability that nobody decided the roles for is an ability
 * that grants too much.
 */

/**
 * Which target each policy's abilities are checked against. Every file in app/Policies must
 * appear here (asserted below), so adding a policy forces a decision rather than being skipped.
 */
function policyTargets(): array
{
    return [
        AssetPolicy::class => 'asset',
        UserPolicy::class => 'other-user',
        SystemPolicy::class => SystemController::class,
    ];
}

/**
 * The authoritative role × ability matrix: ability => [admin, editor, api].
 *
 * `AssetPolicy::move` and `bulkForceDelete` additionally require `maintenance_mode`, which this
 * file enables in beforeEach so the matrix isolates the *role* dimension. The double gate
 * itself is pinned by tests/Unit/Policies/AssetPolicyTest.php.
 *
 * @return array<string, array{admin: bool, editor: bool, api: bool}>
 */
function policyMatrix(): array
{
    $all = ['admin' => true, 'editor' => true, 'api' => true];
    $staff = ['admin' => true, 'editor' => true, 'api' => false];
    $adminOnly = ['admin' => true, 'editor' => false, 'api' => false];

    return [
        'AssetPolicy::viewAny' => $all,
        'AssetPolicy::view' => $all,
        'AssetPolicy::create' => $all,
        'AssetPolicy::update' => $all,
        'AssetPolicy::bulkDownload' => $all,

        'AssetPolicy::replace' => $staff,
        'AssetPolicy::delete' => $staff,
        'AssetPolicy::restore' => $staff,
        'AssetPolicy::bulkTrash' => $staff,
        'AssetPolicy::bulkRestore' => $staff,

        'AssetPolicy::forceDelete' => $adminOnly,
        'AssetPolicy::discover' => $adminOnly,
        'AssetPolicy::export' => $adminOnly,
        'AssetPolicy::move' => $adminOnly,
        'AssetPolicy::bulkForceDelete' => $adminOnly,

        'UserPolicy::viewAny' => $adminOnly,
        'UserPolicy::create' => $adminOnly,
        'UserPolicy::update' => $adminOnly,
        'UserPolicy::delete' => $adminOnly,
        'UserPolicy::clearPasskeys' => $adminOnly,

        'SystemPolicy::access' => $adminOnly,
    ];
}

/**
 * Every public ability method across every policy class, keyed "ShortClass::method".
 *
 * @return array<string, array{class: class-string, method: string}>
 */
function policyAbilities(): array
{
    $abilities = [];

    foreach (array_keys(policyTargets()) as $class) {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || str_starts_with($method->getName(), '__')) {
                continue;
            }

            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $abilities[$reflection->getShortName().'::'.$method->getName()] = [
                'class' => $class,
                'method' => $method->getName(),
            ];
        }
    }

    return $abilities;
}

beforeEach(function () {
    // Unblocks AssetPolicy::move / bulkForceDelete so the matrix reads as roles only.
    Setting::set('maintenance_mode', '1', 'boolean', 'general');
});

// ─── REQ-2: completeness ──────────────────────────────────────────────────────

test('every policy ability appears in the role matrix', function () {
    $undeclared = array_values(array_diff(array_keys(policyAbilities()), array_keys(policyMatrix())));

    expect($undeclared)->toBe([],
        'These policy abilities exist in app/Policies but are not in policyMatrix(): '
        .implode(', ', $undeclared)
        .'. Add them with the roles they are meant to grant. An ability nobody stated the roles '
        .'for is how a role ends up with more than it was given (ADR-002).'
    );
});

test('the role matrix has no entries for abilities that no longer exist', function () {
    $stale = array_values(array_diff(array_keys(policyMatrix()), array_keys(policyAbilities())));

    expect($stale)->toBe([],
        'These policyMatrix() entries no longer match a policy method: '.implode(', ', $stale)
    );
});

test('every policy file is covered by this audit', function () {
    $files = SourceScanner::phpFilesUnder(app_path('Policies'));

    $discovered = array_map(
        fn ($file) => 'App\\Policies\\'.basename($file, '.php'),
        $files
    );

    $uncovered = array_values(array_diff($discovered, array_keys(policyTargets())));

    expect($uncovered)->toBe([],
        'These policies are not in policyTargets(), so none of their abilities are audited: '
        .implode(', ', $uncovered)
    );
});

// ─── REQ-2: correctness ───────────────────────────────────────────────────────

test('each role gets exactly the abilities the matrix grants it', function () {
    $abilities = policyAbilities();

    $users = [
        'admin' => User::factory()->admin()->create(),
        'editor' => User::factory()->editor()->create(),
        'api' => User::factory()->apiUser()->create(),
    ];

    $targets = [
        'asset' => Asset::factory()->create(),
        'other-user' => User::factory()->editor()->create(),
    ];

    $wrong = [];

    foreach (policyMatrix() as $key => $expected) {
        ['class' => $class, 'method' => $method] = $abilities[$key];
        $target = policyTargets()[$class];
        $argument = $targets[$target] ?? $target;

        foreach ($expected as $role => $shouldAllow) {
            $actual = $users[$role]->can($method, $argument);

            if ($actual !== $shouldAllow) {
                $wrong[] = sprintf(
                    '%s for %s: expected %s, got %s',
                    $key, $role, $shouldAllow ? 'allow' : 'deny', $actual ? 'allow' : 'deny'
                );
            }
        }
    }

    expect($wrong)->toBe([], "The policy layer disagrees with the matrix:\n  ".implode("\n  ", $wrong));
});

// ─── REQ-2: no blanket grants ─────────────────────────────────────────────────

/**
 * True when $class::$method's entire body is an unconditional grant.
 *
 * Reads the source rather than calling the method, because the point is the absence of a role
 * check — a method that returns true for a reason looks identical from the outside.
 */
function policyIsBlanketGrant(string $class, string $method): bool
{
    $reflection = new ReflectionMethod($class, $method);
    $source = file($reflection->getFileName());

    $body = implode('', array_slice(
        $source,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1
    ));

    // Everything between the first { and the last } of the method.
    $inner = substr($body, strpos($body, '{') + 1, strrpos($body, '}') - strpos($body, '{') - 1);
    $normalised = preg_replace('/\s+/', ' ', trim($inner));

    return preg_match('/^return (true|1);?$/i', rtrim($normalised, ';').';') === 1;
}

/**
 * ADR-002: abilities enumerate their roles rather than returning true, so adding a role means
 * opting it into each ability instead of inheriting everything.
 */
test('no policy ability is a bare return true', function () {
    $stubs = [];

    foreach (policyAbilities() as $key => $ability) {
        if (policyIsBlanketGrant($ability['class'], $ability['method'])) {
            $stubs[] = $key;
        }
    }

    expect($stubs)->toBe([],
        'These abilities grant unconditionally: '.implode(', ', $stubs)
        .'. ADR-002 requires every ability to name the roles it allows.'
    );
});

/** The detector has to be able to fire, or its green tick means nothing. */
test('the blanket-grant detector actually catches a return true ability', function () {
    $stub = new class
    {
        public function blanket(User $user): bool
        {
            return true;
        }

        public function checked(User $user): bool
        {
            return $user->isAdmin();
        }
    };

    expect(policyIsBlanketGrant($stub::class, 'blanket'))->toBeTrue();
    expect(policyIsBlanketGrant($stub::class, 'checked'))->toBeFalse();
});

/**
 * A `before()` hook or `Gate::before()` returning true for admins would widen every ability at
 * once — including any future ability that was never reviewed. ORCA deliberately has neither,
 * so each policy lists admin explicitly.
 */
test('no policy short-circuits the matrix with a before hook', function () {
    $hooks = [];

    foreach (array_keys(policyTargets()) as $class) {
        if ((new ReflectionClass($class))->hasMethod('before')) {
            $hooks[] = $class.'::before()';
        }
    }

    foreach (SourceScanner::callSitesUnder([app_path()], 'Gate::before(') as $site) {
        $hooks[] = $site['file'].' → Gate::before()';
    }

    expect($hooks)->toBe([],
        'These grant abilities ahead of the policy methods: '.implode(', ', $hooks)
        .'. A blanket pre-authorisation also covers every ability added later, which no one '
        .'reviews. Keep the grants in the abilities themselves (ADR-002).'
    );
});

// ─── REQ-2: role-set drift ────────────────────────────────────────────────────

/**
 * The matrix is only complete if it covers every role that can exist. `users.role` is the
 * authority (NOT NULL, no default — specs/features/authentication.md REQ-8), so a fourth role
 * added there must show up as a column in the matrix rather than silently inheriting whatever
 * the abilities' boolean logic happens to do with it.
 */
test('the matrix covers every role the database permits', function () {
    $ddl = DB::selectOne("select sql from sqlite_master where type = 'table' and name = 'users'")->sql;

    expect($ddl)->toMatch('/role\s+VARCHAR\(\d+\)\s+CHECK\(role IN \([^)]*\)\)/i');

    preg_match('/role\s+VARCHAR\(\d+\)\s+CHECK\(role IN \(([^)]*)\)\)/i', $ddl, $matches);

    $permitted = array_map(
        fn ($value) => trim($value, " '\""),
        explode(',', $matches[1])
    );

    sort($permitted);
    $covered = array_keys(policyMatrix()['AssetPolicy::viewAny']);
    sort($covered);

    expect($covered)->toBe($permitted,
        'users.role permits ['.implode(', ', $permitted).'] but the matrix covers ['
        .implode(', ', $covered).']. Every role needs an explicit verdict for every ability.'
    );
})->skip(fn () => DB::getDriverName() !== 'sqlite', 'Reads the SQLite CHECK constraint; CI runs SQLite.');

// ─── self-check ───────────────────────────────────────────────────────────────

test('the ability scanner sees the known policy surface', function () {
    expect(policyAbilities())
        ->toHaveCount(21)
        ->toHaveKeys(['AssetPolicy::forceDelete', 'UserPolicy::delete', 'SystemPolicy::access']);
});
