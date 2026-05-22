<?php

namespace App\Services;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Support\Collection;

class PasskeyService
{
    /**
     * Maximum number of passkeys allowed per user.
     */
    public const MAX_CREDENTIALS_PER_USER = 10;

    /**
     * List the user's registered passkeys, newest first.
     *
     * @return Collection<int, Passkey>
     */
    public function listCredentials(User $user): Collection
    {
        return $user->passkeys()->latest()->get();
    }

    /**
     * Check whether the user has reached the per-user passkey limit.
     */
    public function hasReachedLimit(User $user): bool
    {
        return $user->passkeys()->count() >= self::MAX_CREDENTIALS_PER_USER;
    }

    /**
     * Rename a passkey. Returns the passkey or null if not found / not owned.
     */
    public function renameCredential(User $user, string $credentialId, ?string $name): ?Passkey
    {
        /** @var Passkey|null $passkey */
        $passkey = $user->passkeys()->where('credential_id', $credentialId)->first();

        if (! $passkey) {
            return null;
        }

        $name = $name === null ? null : trim($name);
        $passkey->name = $name === '' || $name === null ? __('Passkey') : $name;
        $passkey->save();

        return $passkey;
    }

    /**
     * Delete a passkey owned by the user. Returns true on success.
     */
    public function deleteCredential(User $user, string $credentialId): bool
    {
        return (bool) $user->passkeys()->where('credential_id', $credentialId)->delete();
    }

    /**
     * Remove all passkeys for a user (admin recovery action).
     */
    public function clearAllCredentials(User $user): int
    {
        return $user->passkeys()->delete();
    }
}
