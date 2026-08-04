<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to a user account — see specs/features/user-audit-log.md.
 *
 * Append-only: nothing in the app updates or deletes these rows. `UPDATED_AT` is null
 * because the table has no such column (REQ-1) — Eloquent would otherwise try to set it.
 */
class UserAuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * The only attributes recorded on an update. An allowlist, not a denylist, so a
     * column added to `users` later cannot leak into the trail by default (REQ-3).
     */
    public const WATCHED = ['role', 'email', 'name'];

    public const EVENTS = ['created', 'updated', 'deleted'];

    protected $fillable = [
        'user_id',
        'user_label',
        'actor_id',
        'actor_label',
        'event',
        'changes',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    /** The account that was changed. Null once that account is deleted. */
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Who made the change. Null for console/system writes. */
    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    public function scopeOfEvent(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }
}
