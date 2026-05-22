<?php

namespace App\Console\Commands;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PasskeysListCommand extends Command
{
    protected $signature = 'passkeys:list
                            {--user= : Filter by user email}
                            {--role= : Filter by user role (admin, editor, api)}';

    protected $description = 'List all registered passkeys with their associated users';

    public function handle(): int
    {
        $query = Passkey::query()
            ->with('user')
            ->orderByDesc('created_at');

        $userEmail = $this->option('user');
        $role = $this->option('role');

        if ($userEmail) {
            $user = User::where('email', $userEmail)->first();
            if (! $user) {
                $this->error("User not found: {$userEmail}");

                return Command::FAILURE;
            }
            $query->where('user_id', $user->getKey());
        }

        $passkeys = $query->get();

        if ($role) {
            $passkeys = $passkeys->filter(
                fn (Passkey $passkey) => $passkey->user instanceof User
                    && $passkey->user->role === $role
            );
        }

        if ($passkeys->isEmpty()) {
            $this->info('No passkeys found.');

            return Command::SUCCESS;
        }

        $this->info("Found {$passkeys->count()} passkey(s):\n");

        $fmt = fn ($value) => $value ? Carbon::parse($value)->format('Y-m-d H:i') : null;

        $rows = $passkeys->map(function (Passkey $passkey) use ($fmt) {
            $user = $passkey->user;

            return [
                'ID' => Str::limit($passkey->credential_id, 16, '…'),
                'Name' => $passkey->name ?: '—',
                'User' => $user?->name ?? 'N/A',
                'Email' => $user?->email ?? 'N/A',
                'Role' => $user?->role ?? 'N/A',
                'Created' => $fmt($passkey->created_at) ?? '—',
                'Last Used' => $fmt($passkey->last_used_at) ?? 'Never',
            ];
        })->toArray();

        $this->table(
            ['ID', 'Name', 'User', 'Email', 'Role', 'Created', 'Last Used'],
            $rows
        );

        return Command::SUCCESS;
    }
}
