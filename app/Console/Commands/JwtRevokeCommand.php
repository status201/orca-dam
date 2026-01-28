<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class JwtRevokeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jwt:revoke
                            {email : Email of the user to revoke JWT secret for}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Revoke a user\'s JWT secret';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("User not found: {$email}");
            return Command::FAILURE;
        }

        if (!$user->hasJwtSecret()) {
            $this->info('User does not have a JWT secret.');
            return Command::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm("Revoke JWT secret for {$user->name} ({$user->email})?")) {
            $this->info('Operation cancelled.');
            return Command::FAILURE;
        }

        $user->update([
            'jwt_secret' => null,
            'jwt_secret_generated_at' => null,
        ]);

        $this->info('JWT secret revoked successfully.');
        $this->line("User: {$user->name} ({$user->email})");

        return Command::SUCCESS;
    }
}
