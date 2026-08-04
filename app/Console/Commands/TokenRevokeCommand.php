<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

class TokenRevokeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'token:revoke
                            {id? : The ID of the token to revoke}
                            {--user= : Revoke all tokens for a user (by email)}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Revoke an API token by ID or all tokens for a user';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tokenId = $this->argument('id');
        $userEmail = $this->option('user');
        $force = $this->option('force');

        // Must provide either token ID or user email
        if (! $tokenId && ! $userEmail) {
            $this->error('Please provide either a token ID or --user=email');
            $this->newLine();
            $this->line('Usage:');
            $this->line('  php artisan token:revoke 5              # Revoke token with ID 5');
            $this->line('  php artisan token:revoke --user=a@b.com # Revoke all tokens for user');

            return Command::FAILURE;
        }

        // Revoke all tokens for a user
        if ($userEmail) {
            return $this->revokeUserTokens($userEmail, $force);
        }

        // Revoke single token by ID
        return $this->revokeSingleToken($tokenId, $force);
    }

    /**
     * Revoke a single token by ID.
     */
    protected function revokeSingleToken(int $tokenId, bool $force): int
    {
        $token = PersonalAccessToken::with('tokenable')->find($tokenId);

        if (! $token) {
            $this->error("Token not found: {$tokenId}");

            return Command::FAILURE;
        }

        $user = $token->tokenable;
        $this->info('Token details:');
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $token->id],
                ['Name', $token->name],
                ['User', $user instanceof User ? $user->name : 'N/A'],
                ['Email', $user instanceof User ? $user->email : 'N/A'],
                ['Created', $token->created_at->format('Y-m-d H:i')],
                ['Last Used', $token->last_used_at ? $token->last_used_at->format('Y-m-d H:i') : 'Never'],
            ]
        );

        if (! $force && ! $this->confirm('Are you sure you want to revoke this token?')) {
            $this->info('Operation cancelled.');

            return Command::SUCCESS;
        }

        $token->delete();
        $this->info('Token revoked successfully.');

        return Command::SUCCESS;
    }

    /**
     * Revoke all tokens for a user.
     */
    protected function revokeUserTokens(string $email, bool $force): int
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User not found: {$email}");

            return Command::FAILURE;
        }

        $tokens = $user->tokens;

        if ($tokens->isEmpty()) {
            $this->info("No tokens found for user: {$email}");

            return Command::SUCCESS;
        }

        $this->info("Found {$tokens->count()} token(s) for {$user->name} ({$email}):");
        $this->newLine();

        $this->table(
            ['ID', 'Name', 'Created', 'Last Used'],
            $tokens->map(fn ($t) => [
                $t->id,
                $t->name,
                $t->created_at->format('Y-m-d H:i'),
                $t->last_used_at ? $t->last_used_at->format('Y-m-d H:i') : 'Never',
            ])->toArray()
        );

        if (! $force && ! $this->confirm("Revoke all {$tokens->count()} token(s) for this user?")) {
            $this->info('Operation cancelled.');

            return Command::SUCCESS;
        }

        $user->tokens()->delete();
        $this->info("Revoked {$tokens->count()} token(s) for {$email}.");

        return Command::SUCCESS;
    }
}
