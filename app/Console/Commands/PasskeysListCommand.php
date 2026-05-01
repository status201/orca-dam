<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laragear\WebAuthn\Models\WebAuthnCredential;

class PasskeysListCommand extends Command
{
    protected $signature = 'passkeys:list
                            {--user= : Filter by user email}
                            {--role= : Filter by user role (admin, editor, api)}';

    protected $description = 'List all registered passkeys with their associated users';

    public function handle(): int
    {
        $query = WebAuthnCredential::query()
            ->with('authenticatable')
            ->orderByDesc('created_at');

        $userEmail = $this->option('user');
        $role = $this->option('role');

        if ($userEmail) {
            $user = User::where('email', $userEmail)->first();
            if (! $user) {
                $this->error("User not found: {$userEmail}");

                return Command::FAILURE;
            }
            $query->where('authenticatable_type', $user->getMorphClass())
                ->where('authenticatable_id', $user->getKey());
        }

        $credentials = $query->get();

        if ($role) {
            $credentials = $credentials->filter(function (WebAuthnCredential $credential) use ($role) {
                return $credential->authenticatable instanceof User
                    && $credential->authenticatable->role === $role;
            });
        }

        if ($credentials->isEmpty()) {
            $this->info('No passkeys found.');

            return Command::SUCCESS;
        }

        $this->info("Found {$credentials->count()} passkey(s):\n");

        $fmt = fn ($value) => $value ? Carbon::parse($value)->format('Y-m-d H:i') : null;

        $rows = $credentials->map(function (WebAuthnCredential $credential) use ($fmt) {
            $user = $credential->authenticatable;

            return [
                'ID' => Str::limit($credential->id, 16, '…'),
                'Alias' => $credential->alias ?: '—',
                'User' => $user?->name ?? 'N/A',
                'Email' => $user?->email ?? 'N/A',
                'Role' => $user?->role ?? 'N/A',
                'Status' => $credential->isEnabled() ? 'Enabled' : 'Disabled',
                'Created' => $fmt($credential->created_at) ?? '—',
                'Last Used' => $fmt($credential->last_used_at) ?? 'Never',
            ];
        })->toArray();

        $this->table(
            ['ID', 'Alias', 'User', 'Email', 'Role', 'Status', 'Created', 'Last Used'],
            $rows
        );

        return Command::SUCCESS;
    }
}
