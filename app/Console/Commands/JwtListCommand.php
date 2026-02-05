<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class JwtListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jwt:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all users with JWT secrets';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $users = User::whereNotNull('jwt_secret')
            ->get(['id', 'name', 'email', 'role', 'jwt_secret_generated_at']);

        if ($users->isEmpty()) {
            $this->info('No users have JWT secrets.');

            return Command::SUCCESS;
        }

        $this->info("Found {$users->count()} user(s) with JWT secrets:");
        $this->newLine();

        $this->table(
            ['ID', 'Name', 'Email', 'Role', 'Generated At'],
            $users->map(fn ($user) => [
                $user->id,
                $user->name,
                $user->email,
                $user->role,
                $user->jwt_secret_generated_at?->format('Y-m-d H:i'),
            ])
        );

        $jwtEnabled = config('jwt.enabled', false);
        $this->newLine();
        $this->line('JWT Authentication: '.($jwtEnabled ? '<fg=green>Enabled</>' : '<fg=yellow>Disabled</>'));

        if (! $jwtEnabled) {
            $this->warn('Set JWT_ENABLED=true in .env to enable JWT authentication.');
        }

        return Command::SUCCESS;
    }
}
