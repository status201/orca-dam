<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class TwoFactorStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'two-factor:status
                            {--email= : Filter by user email}
                            {--role= : Filter by user role (admin, editor, api)}
                            {--enabled : Only show users with 2FA enabled}
                            {--disabled : Only show users with 2FA disabled}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List users and their two-factor authentication status';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $query = User::query();

        // Filter by email
        if ($email = $this->option('email')) {
            $query->where('email', 'like', "%{$email}%");
        }

        // Filter by role
        if ($role = $this->option('role')) {
            if (! in_array($role, ['admin', 'editor', 'api'])) {
                $this->error("Invalid role: {$role}. Must be admin, editor, or api.");

                return Command::FAILURE;
            }
            $query->where('role', $role);
        }

        $users = $query->orderBy('name')->get();

        // Filter by 2FA status
        if ($this->option('enabled')) {
            $users = $users->filter(fn ($user) => $user->hasTwoFactorEnabled());
        } elseif ($this->option('disabled')) {
            $users = $users->filter(fn ($user) => ! $user->hasTwoFactorEnabled());
        }

        if ($users->isEmpty()) {
            $this->info('No users found matching the criteria.');

            return Command::SUCCESS;
        }

        $rows = $users->map(function ($user) {
            $status = $user->hasTwoFactorEnabled() ? '<fg=green>Enabled</>' : '<fg=gray>Disabled</>';
            $canEnable = $user->canEnableTwoFactor() ? 'Yes' : 'No';
            $enabledAt = $user->two_factor_confirmed_at
                ? $user->two_factor_confirmed_at->format('Y-m-d H:i')
                : '-';
            $recoveryCodes = $user->hasTwoFactorEnabled()
                ? count($user->two_factor_recovery_codes ?? [])
                : '-';

            return [
                $user->id,
                $user->name,
                $user->email,
                $user->role,
                $status,
                $canEnable,
                $enabledAt,
                $recoveryCodes,
            ];
        });

        $this->table(
            ['ID', 'Name', 'Email', 'Role', '2FA Status', 'Can Enable', 'Enabled At', 'Recovery Codes'],
            $rows
        );

        // Summary
        $total = $users->count();
        $enabled = $users->filter(fn ($user) => $user->hasTwoFactorEnabled())->count();

        $this->newLine();
        $this->info("Total users: {$total} | 2FA enabled: {$enabled} | 2FA disabled: ".($total - $enabled));

        return Command::SUCCESS;
    }
}
