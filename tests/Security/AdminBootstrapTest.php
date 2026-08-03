<?php

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * REQ-9 of specs/features/security-invariants.md — the bootstrap seeder cannot mint a
 * published-credential admin.
 *
 * `AdminUserSeeder` is a documented production step (DEPLOYMENT.md, straight after
 * `migrate --force`), and it used to hardcode `admin@orca.dam` / `password` with no guard. This
 * repository is public, so an installation seeded from those defaults shipped with an admin
 * account whose login anyone could read on GitHub — and nothing ever forced it to change.
 *
 * No test in this repository executed a seeder before this one. That is part of why the problem
 * survived: the REQ-4 provisioning scan reads the file and sees an explicit `'role' => 'admin'`,
 * which is all it claims to check, and nothing at all exercised the credentials.
 *
 * The seeder is invoked directly rather than through `$this->seed()` where an exception is
 * expected, because the guard's job is to throw — `$this->command` is null on that path, which is
 * exactly why the seeder uses null-safe `$this->command?->` calls.
 */

/** Run the seeder as if APP_ENV were $environment. */
function seedAdminIn(string $environment): void
{
    app()->detectEnvironment(fn () => $environment);

    (new AdminUserSeeder)->run();
}

function setBootstrapCredentials(?string $email, ?string $password, ?string $name = null): void
{
    config([
        'orca.admin_bootstrap.email' => $email,
        'orca.admin_bootstrap.password' => $password,
        'orca.admin_bootstrap.name' => $name,
    ]);
}

beforeEach(function () {
    // config/orca.php reads env(), which is unset in the test environment; be explicit anyway so
    // a developer's own .env cannot make these tests pass or fail for the wrong reason.
    setBootstrapCredentials(null, null);
});

// ─── production: refuses without explicit credentials ─────────────────────────

test('production refuses to seed an admin when no credentials are configured', function () {
    expect(fn () => seedAdminIn('production'))
        ->toThrow(RuntimeException::class, 'without explicit credentials');

    expect(User::count())->toBe(0);
});

test('production refuses when only the email is configured', function () {
    setBootstrapCredentials('ops@example.com', null);

    expect(fn () => seedAdminIn('production'))->toThrow(RuntimeException::class);

    expect(User::count())->toBe(0);
});

test('production refuses when only the password is configured', function () {
    setBootstrapCredentials(null, 'a-sufficiently-long-password');

    expect(fn () => seedAdminIn('production'))->toThrow(RuntimeException::class);

    expect(User::count())->toBe(0);
});

/**
 * The load-bearing case. Supplying the published default explicitly must not be a way around the
 * guard — otherwise the fix is only a rename of where the same credential comes from.
 */
test('production refuses the published development password even when set explicitly', function () {
    setBootstrapCredentials('ops@example.com', 'password');

    expect(fn () => seedAdminIn('production'))
        ->toThrow(RuntimeException::class, 'well-known password');

    expect(User::count())->toBe(0);
});

test('production refuses other trivially guessable passwords', function (string $password) {
    setBootstrapCredentials('ops@example.com', $password);

    expect(fn () => seedAdminIn('production'))->toThrow(RuntimeException::class);

    expect(User::count())->toBe(0);
})->with(['admin', 'admin123', 'secret', 'changeme', 'password123', 'orca']);

test('the well-known password check is case insensitive', function () {
    setBootstrapCredentials('ops@example.com', 'PassWord');

    expect(fn () => seedAdminIn('production'))->toThrow(RuntimeException::class);

    expect(User::count())->toBe(0);
});

test('production refuses a password that fails the app password policy', function () {
    // Shorter than Password::defaults() allows, and not on the well-known list, so this proves
    // the policy check fires independently of the denylist.
    setBootstrapCredentials('ops@example.com', 'x7Qk');

    expect(fn () => seedAdminIn('production'))->toThrow(RuntimeException::class);

    expect(User::count())->toBe(0);
});

test('production refuses a malformed email', function () {
    setBootstrapCredentials('not-an-email', 'a-sufficiently-long-password');

    expect(fn () => seedAdminIn('production'))->toThrow(RuntimeException::class);

    expect(User::count())->toBe(0);
});

// ─── production: succeeds with real credentials ───────────────────────────────

test('production seeds an admin when given usable credentials', function () {
    setBootstrapCredentials('ops@example.com', 'C0rrect-Horse-Battery', 'Ops Admin');

    seedAdminIn('production');

    $admin = User::sole();

    expect($admin->email)->toBe('ops@example.com');
    expect($admin->name)->toBe('Ops Admin');
    expect($admin->role)->toBe('admin');
    expect(Hash::check('C0rrect-Horse-Battery', $admin->password))->toBeTrue();
});

test('production does not echo the operator supplied password', function () {
    setBootstrapCredentials('ops@example.com', 'C0rrect-Horse-Battery');
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('db:seed', ['--class' => AdminUserSeeder::class, '--force' => true])
        ->doesntExpectOutputToContain('C0rrect-Horse-Battery')
        ->assertSuccessful();
});

// ─── non-production: the development convenience is unchanged ──────────────────

test('a development environment still seeds the default admin', function (string $environment) {
    seedAdminIn($environment);

    $admin = User::sole();

    expect($admin->email)->toBe('admin@orca.dam');
    expect($admin->role)->toBe('admin');
    expect(Hash::check('password', $admin->password))->toBeTrue();
})->with(['local', 'testing', 'e2e']);

test('configured credentials are honoured outside production too', function () {
    setBootstrapCredentials('dev@example.com', 'another-long-password');

    seedAdminIn('local');

    expect(User::sole()->email)->toBe('dev@example.com');
});

// ─── idempotency ──────────────────────────────────────────────────────────────

/**
 * The previous version used `User::create`, so a second run hit the unique email index and threw
 * a QueryException. On a production deploy that reads as a failed deployment rather than as
 * "the admin already exists".
 */
test('running the seeder twice leaves a single admin and does not throw', function () {
    seedAdminIn('local');
    seedAdminIn('local');

    expect(User::where('email', 'admin@orca.dam')->count())->toBe(1);
    expect(User::count())->toBe(1);
});

test('a second run does not overwrite an existing account', function () {
    seedAdminIn('local');

    User::where('email', 'admin@orca.dam')->update(['name' => 'Renamed By Operator']);

    seedAdminIn('local');

    expect(User::sole()->name)->toBe('Renamed By Operator');
});

// ─── the seeder is callable without a console command attached ────────────────

/**
 * The old version called `$this->command->info(...)` unguarded, so invoking `run()` outside an
 * artisan context threw on a null property — which is why none of the above was testable before.
 */
test('the seeder runs without a command instance attached', function () {
    expect(fn () => (new AdminUserSeeder)->run())->not->toThrow(Error::class);

    expect(User::count())->toBe(1);
});
