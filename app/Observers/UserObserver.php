<?php

namespace App\Observers;

use App\Models\User;
use App\Models\UserAuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Records user administration to the audit trail — see specs/features/user-audit-log.md.
 *
 * Registered in AppServiceProvider::boot(). Runs on the model event, i.e. after the write
 * has committed, so a row always describes a change that really happened.
 */
class UserObserver
{
    public function created(User $user): void
    {
        $this->record($user, 'created', $this->snapshot($user, 'to'));

        if ($user->role === 'admin') {
            $this->warnOnAdminGrant($user, 'created as admin');
        }
    }

    public function updated(User $user): void
    {
        $changes = $this->watchedChanges($user);

        // Logins stamp last_login_at, profile saves touch preferences, password updates
        // rewrite the hash — none of that belongs in an audit trail, and recording it
        // would bury the events that matter (REQ-2).
        if ($changes === []) {
            return;
        }

        $this->record($user, 'updated', $changes);

        if (isset($changes['role']) && $changes['role']['to'] === 'admin') {
            $this->warnOnAdminGrant($user, 'promoted to admin');
        }
    }

    public function deleted(User $user): void
    {
        $this->record($user, 'deleted', $this->snapshot($user, 'from'));
    }

    /**
     * The watched attributes that actually moved, as {attribute: {from, to}}.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function watchedChanges(User $user): array
    {
        $changes = [];

        foreach (UserAuditLog::WATCHED as $attribute) {
            if (! array_key_exists($attribute, $user->getChanges())) {
                continue;
            }

            $changes[$attribute] = [
                'from' => $user->getOriginal($attribute),
                'to' => $user->getAttribute($attribute),
            ];
        }

        return $changes;
    }

    /**
     * The full watched state, one-sided — creation has no "from", deletion no "to".
     *
     * @return array<string, array<string, mixed>>
     */
    private function snapshot(User $user, string $side): array
    {
        $snapshot = [];

        foreach (UserAuditLog::WATCHED as $attribute) {
            $snapshot[$attribute] = [$side => $user->getAttribute($attribute)];
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function record(User $user, string $event, array $changes): void
    {
        // An audit write must never break the operation it is auditing (REQ-6) — a full
        // disk or a migration not yet run must not make user administration fail.
        try {
            $actor = Auth::user();

            UserAuditLog::create([
                // The row for a deletion cannot carry the FK: the model event fires after
                // the DELETE, so the id no longer exists and the insert would violate the
                // constraint (and then be swallowed below, losing the entry silently).
                // user_label is what carries identity for a gone account — REQ-4.
                'user_id' => $event === 'deleted' ? null : $user->id,
                'user_label' => (string) $user->email,
                'actor_id' => $actor?->id,
                'actor_label' => $actor?->email
                    ?? (app()->runningInConsole() ? 'console' : 'system'),
                'event' => $event,
                'changes' => $changes === [] ? null : $changes,
                // request()->ip() answers 127.0.0.1 under artisan, which reads as a real
                // network origin. A console change has no remote address — say so.
                'ip' => app()->runningInConsole() ? null : request()->ip(),
                'user_agent' => app()->runningInConsole()
                    ? null
                    : (substr((string) request()->userAgent(), 0, 255) ?: null),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to write user audit log', [
                'event' => $event,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The alert half of the trail (REQ-5): the DB row answers questions later, this line
     * is for anything watching logs now.
     */
    private function warnOnAdminGrant(User $user, string $what): void
    {
        $actor = Auth::user();

        Log::warning("User {$what}", [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'actor_id' => $actor?->id,
            'actor_email' => $actor?->email
                ?? (app()->runningInConsole() ? 'console' : 'system'),
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
