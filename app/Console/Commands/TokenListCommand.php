<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

class TokenListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'token:list
                            {--user= : Filter by user email}
                            {--role= : Filter by user role (admin, editor, api)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all API tokens with their associated users';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $query = PersonalAccessToken::with('tokenable')
            ->orderBy('created_at', 'desc');

        // Filter by user email if provided
        $userEmail = $this->option('user');
        $role = $this->option('role');

        if ($userEmail) {
            $user = User::where('email', $userEmail)->first();
            if (! $user) {
                $this->error("User not found: {$userEmail}");

                return Command::FAILURE;
            }
            $query->where('tokenable_type', User::class)
                ->where('tokenable_id', $user->id);
        }

        $tokens = $query->get();

        // Filter by role after fetching (since role is on User, not token)
        if ($role) {
            $tokens = $tokens->filter(function ($token) use ($role) {
                $owner = $token->tokenable;

                return $owner instanceof User && $owner->role === $role;
            });
        }

        if ($tokens->isEmpty()) {
            $this->info('No tokens found.');

            return Command::SUCCESS;
        }

        $this->info("Found {$tokens->count()} token(s):\n");

        $tableData = $tokens->map(function ($token) {
            $user = $token->tokenable;

            return [
                'ID' => $token->id,
                'Name' => $token->name,
                'User' => $user instanceof User ? $user->name : 'N/A',
                'Email' => $user instanceof User ? $user->email : 'N/A',
                'Role' => $user instanceof User ? $user->role : 'N/A',
                'Created' => $token->created_at->format('Y-m-d H:i'),
                'Last Used' => $token->last_used_at ? $token->last_used_at->format('Y-m-d H:i') : 'Never',
            ];
        })->toArray();

        $this->table(
            ['ID', 'Name', 'User', 'Email', 'Role', 'Created', 'Last Used'],
            $tableData
        );

        return Command::SUCCESS;
    }
}
