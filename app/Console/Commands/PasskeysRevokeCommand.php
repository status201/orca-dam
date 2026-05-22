<?php

namespace App\Console\Commands;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PasskeysRevokeCommand extends Command
{
    protected $signature = 'passkeys:revoke
                            {id? : The credential ID (or its prefix) to revoke}
                            {--user= : Revoke all passkeys for a user (by email)}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Revoke a passkey by ID or all passkeys for a user';

    public function handle(): int
    {
        $id = $this->argument('id');
        $userEmail = $this->option('user');
        $force = (bool) $this->option('force');

        if (! $id && ! $userEmail) {
            $this->error('Please provide either a credential ID or --user=email');
            $this->newLine();
            $this->line('Usage:');
            $this->line('  php artisan passkeys:revoke <id>             # Revoke a single passkey');
            $this->line('  php artisan passkeys:revoke --user=a@b.com   # Revoke all passkeys for user');

            return Command::FAILURE;
        }

        if ($userEmail) {
            return $this->revokeForUser($userEmail, $force);
        }

        return $this->revokeSingle($id, $force);
    }

    protected function revokeSingle(string $id, bool $force): int
    {
        $passkey = Passkey::with('user')->where('credential_id', $id)->first()
            ?? Passkey::with('user')->where('credential_id', 'like', $id.'%')->first();

        if (! $passkey) {
            $this->error("Passkey not found: {$id}");

            return Command::FAILURE;
        }

        $user = $passkey->user;

        $fmt = fn ($value) => $value ? Carbon::parse($value)->format('Y-m-d H:i') : null;

        $this->info('Passkey details:');
        $this->table(['Field', 'Value'], [
            ['ID', $passkey->credential_id],
            ['Name', $passkey->name ?: '—'],
            ['User', $user?->name ?? 'N/A'],
            ['Email', $user?->email ?? 'N/A'],
            ['Created', $fmt($passkey->created_at) ?? '—'],
            ['Last Used', $fmt($passkey->last_used_at) ?? 'Never'],
        ]);

        if (! $force && ! $this->confirm('Are you sure you want to revoke this passkey?')) {
            $this->info('Operation cancelled.');

            return Command::SUCCESS;
        }

        $passkey->delete();
        $this->info('Passkey revoked successfully.');

        return Command::SUCCESS;
    }

    protected function revokeForUser(string $email, bool $force): int
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User not found: {$email}");

            return Command::FAILURE;
        }

        $passkeys = $user->passkeys()->get();

        if ($passkeys->isEmpty()) {
            $this->info("No passkeys found for user: {$email}");

            return Command::SUCCESS;
        }

        $this->info("Found {$passkeys->count()} passkey(s) for {$user->name} ({$email}):");
        $this->newLine();

        $fmt = fn ($value) => $value ? Carbon::parse($value)->format('Y-m-d H:i') : null;

        $this->table(
            ['ID', 'Name', 'Created', 'Last Used'],
            $passkeys->map(fn (Passkey $p) => [
                Str::limit($p->credential_id, 16, '…'),
                $p->name ?: '—',
                $fmt($p->created_at) ?? '—',
                $fmt($p->last_used_at) ?? 'Never',
            ])->toArray()
        );

        if (! $force && ! $this->confirm("Revoke all {$passkeys->count()} passkey(s) for this user?")) {
            $this->info('Operation cancelled.');

            return Command::SUCCESS;
        }

        $count = $user->passkeys()->delete();
        $this->info("Revoked {$count} passkey(s) for {$email}.");

        return Command::SUCCESS;
    }
}
