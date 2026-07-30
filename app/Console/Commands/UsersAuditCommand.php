<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Reads the user audit trail — see specs/features/user-audit-log.md REQ-7.
 *
 * The trail has no UI in this version, so this is the only way to read it.
 */
class UsersAuditCommand extends Command
{
    protected $signature = 'users:audit
                            {--user= : Filter by the subject user\'s email}
                            {--event= : Filter by event (created, updated, deleted)}
                            {--limit=50 : How many entries to show, newest first}';

    protected $description = 'Show the audit trail of user create / role-change / delete events';

    public function handle(): int
    {
        $query = UserAuditLog::query()->orderByDesc('created_at');

        if ($email = $this->option('user')) {
            // Match on the label too, so a deleted account's history stays reachable
            // by the address it had.
            $user = User::where('email', $email)->first();

            $query->where(function ($q) use ($user, $email) {
                $q->where('user_label', $email);

                if ($user) {
                    $q->orWhere('user_id', $user->getKey());
                }
            });
        }

        if ($event = $this->option('event')) {
            if (! in_array($event, UserAuditLog::EVENTS, true)) {
                $this->error('Unknown event: '.$event.'. Expected one of: '.implode(', ', UserAuditLog::EVENTS));

                return Command::FAILURE;
            }

            $query->ofEvent($event);
        }

        $limit = max(1, (int) $this->option('limit'));
        $entries = $query->limit($limit)->get();

        if ($entries->isEmpty()) {
            $this->info('No audit entries found.');

            return Command::SUCCESS;
        }

        $this->info("Showing {$entries->count()} entry/entries, newest first:\n");

        $this->table(
            ['When', 'Event', 'User', 'Actor', 'Changes', 'IP'],
            $entries->map(fn (UserAuditLog $entry) => [
                $entry->created_at ? Carbon::parse($entry->created_at)->format('Y-m-d H:i') : '—',
                $entry->event,
                $entry->user_label,
                $entry->actor_label,
                $this->describeChanges($entry->changes),
                $entry->ip ?? '—',
            ])->all()
        );

        return Command::SUCCESS;
    }

    /**
     * Render {role: {from: editor, to: admin}} as "role: editor → admin".
     *
     * @param  array<string, array<string, mixed>>|null  $changes
     */
    private function describeChanges(?array $changes): string
    {
        if (! $changes) {
            return '—';
        }

        $parts = [];

        foreach ($changes as $attribute => $change) {
            $from = $change['from'] ?? null;
            $to = $change['to'] ?? null;

            $parts[] = match (true) {
                array_key_exists('from', $change) && array_key_exists('to', $change) => "{$attribute}: {$from} → {$to}",
                array_key_exists('to', $change) => "{$attribute}: {$to}",
                default => "{$attribute}: {$from}",
            };
        }

        return implode(', ', $parts);
    }
}
