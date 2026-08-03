<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

/**
 * Creates the first admin account on a fresh installation — see
 * specs/features/security-invariants.md REQ-9.
 *
 * This seeder is a documented production step (DEPLOYMENT.md, immediately after
 * `migrate --force`), which is what makes its credentials load-bearing. It used to hardcode
 * `admin@orca.dam` / `password` with no guard at all. This repository is public, so those were
 * not weak credentials — they were published ones, and any installation seeded from them shipped
 * with an admin account whose login anybody could read on GitHub.
 *
 * In production the credentials must now come from the environment (`ORCA_ADMIN_EMAIL`,
 * `ORCA_ADMIN_PASSWORD` via config/orca.php) and the refusal is a thrown exception rather than a
 * message. That is deliberate, and it is where this differs from E2eSeeder: E2eSeeder's guard
 * writes to stderr and `return`s, leaving the exit code at 0. That is right for a fixture seeder
 * — being skipped is the intended outcome. Here, being skipped means an operator's deployment
 * script reports success while no admin account exists, so the failure has to be loud.
 *
 * Outside production the old development defaults still apply, so `php artisan db:seed
 * --class=AdminUserSeeder` on a laptop behaves exactly as it did before.
 */
class AdminUserSeeder extends Seeder
{
    /** Development-only fallbacks. Never reachable in production — see run(). */
    private const DEV_NAME = 'Admin User';

    private const DEV_EMAIL = 'admin@orca.dam';

    private const DEV_PASSWORD = 'password';

    /**
     * Passwords refused in production regardless of whether they satisfy the length rule.
     *
     * DEV_PASSWORD is the one that matters: it is committed to a public repository and was the
     * documented default for every installation, so it is the first thing anybody would try.
     * The rest are here because an operator hurrying through a deployment reaches for them.
     */
    private const REJECTED_PASSWORDS = [
        self::DEV_PASSWORD,
        'password123',
        'admin',
        'admin123',
        'secret',
        'changeme',
        'orca',
    ];

    public function run(): void
    {
        $inProduction = app()->environment('production');

        $name = config('orca.admin_bootstrap.name');
        $email = config('orca.admin_bootstrap.email');
        $password = config('orca.admin_bootstrap.password');

        if ($inProduction) {
            $this->assertProductionCredentials($email, $password);
        } else {
            $email = $email ?: self::DEV_EMAIL;
            $password = $password ?: self::DEV_PASSWORD;
        }

        // ORCA_ADMIN_NAME is optional in every environment — it is a display name, not a credential.
        $name = $name ?: self::DEV_NAME;

        // firstOrCreate rather than create: the previous version threw a QueryException on the
        // unique email index if it was ever run twice, which on a production deploy looks like a
        // failed deployment rather than "the admin already exists".
        $admin = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'role' => 'admin',
            ]
        );

        if (! $admin->wasRecentlyCreated) {
            $this->command?->warn("An account already exists for {$email} — left unchanged.");
            $this->command?->warn('Delete it, or use a different ORCA_ADMIN_EMAIL, if you meant to create a new admin.');

            return;
        }

        $this->command?->info("Admin user created: {$email}");

        if ($inProduction) {
            // The operator chose this password; echoing it would only put it into deployment
            // logs and CI output.
            $this->command?->info('Password: the value of ORCA_ADMIN_PASSWORD (not echoed).');

            return;
        }

        $this->command?->info("Password: {$password}");
        $this->command?->warn('Development credentials. Never seed a production database with these — '
            .'set ORCA_ADMIN_EMAIL and ORCA_ADMIN_PASSWORD instead (see DEPLOYMENT.md).');
    }

    /**
     * @throws RuntimeException when production credentials are absent or unusable.
     */
    private function assertProductionCredentials(mixed $email, mixed $password): void
    {
        if (blank($email) || blank($password)) {
            throw new RuntimeException(
                'Refusing to seed an admin account in production without explicit credentials. '
                .'Set ORCA_ADMIN_EMAIL and ORCA_ADMIN_PASSWORD, then re-run. The development '
                .'default ('.self::DEV_EMAIL.' / '.self::DEV_PASSWORD.') is committed to a public '
                .'repository and must never reach production. '
                .'See specs/features/security-invariants.md REQ-9.'
            );
        }

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email' => ['required', 'string', 'email', 'max:255'],
                // The same rule the interactive password flows use (PasswordController,
                // NewPasswordController), so the bootstrap admin is held to the app's own standard
                // rather than a second one invented here.
                'password' => ['required', 'string', Password::defaults()],
            ]
        );

        if ($validator->fails()) {
            throw new RuntimeException(
                'Refusing to seed an admin account in production: '
                .implode(' ', $validator->errors()->all())
                .' Fix ORCA_ADMIN_EMAIL / ORCA_ADMIN_PASSWORD and re-run.'
            );
        }

        if (in_array(mb_strtolower((string) $password), self::REJECTED_PASSWORDS, true)) {
            throw new RuntimeException(
                'Refusing to seed an admin account in production with a well-known password. '
                .'ORCA_ADMIN_PASSWORD is one of the values this project has published or that are '
                .'trivially guessable. Choose something else and re-run.'
            );
        }
    }
}
