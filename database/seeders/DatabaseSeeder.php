<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The role is named explicitly rather than inherited from UserFactory's default.
        // `db:seed` runs against whatever database is configured, so this is a production-
        // reachable creation path; specs/features/authentication.md REQ-8 requires every one of
        // them to state the privileges it grants. Pinned by tests/Security/UserProvisioningTest.php.
        User::factory()->editor()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
