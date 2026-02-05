<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Console\Command;

class TwoFactorDisableCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'two-factor:disable
                            {email : The email address of the user}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Disable two-factor authentication for a user (emergency recovery)';

    /**
     * Execute the console command.
     */
    public function handle(TwoFactorService $twoFactorService): int
    {
        $email = $this->argument('email');

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User not found: {$email}");

            return Command::FAILURE;
        }

        if (! $user->hasTwoFactorEnabled()) {
            $this->info("Two-factor authentication is not enabled for {$email}");

            return Command::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn("You are about to disable two-factor authentication for: {$user->name} ({$email})");

            if (! $this->confirm('Are you sure you want to continue?')) {
                $this->info('Operation cancelled.');

                return Command::SUCCESS;
            }
        }

        $twoFactorService->disableTwoFactor($user);

        $this->info("Two-factor authentication has been disabled for {$email}");

        return Command::SUCCESS;
    }
}
