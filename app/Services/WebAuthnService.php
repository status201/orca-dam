<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Laragear\WebAuthn\Models\WebAuthnCredential;

class WebAuthnService
{
    /**
     * Maximum number of passkeys allowed per user.
     */
    public const MAX_CREDENTIALS_PER_USER = 10;

    /**
     * List the user's registered passkeys, newest first.
     *
     * @return Collection<int, WebAuthnCredential>
     */
    public function listCredentials(User $user): Collection
    {
        return $user->webAuthnCredentials()->latest()->get();
    }

    /**
     * Check whether the user has reached the per-user passkey limit.
     */
    public function hasReachedLimit(User $user): bool
    {
        return $user->webAuthnCredentials()->count() >= self::MAX_CREDENTIALS_PER_USER;
    }

    /**
     * Rename a credential. Returns the credential or null if not found / not owned.
     */
    public function renameCredential(User $user, string $credentialId, ?string $alias): ?WebAuthnCredential
    {
        $credential = $user->webAuthnCredentials()->whereKey($credentialId)->first();

        if (! $credential) {
            return null;
        }

        $alias = $alias === null ? null : trim($alias);
        $credential->alias = $alias === '' ? null : $alias;
        $credential->save();

        return $credential;
    }

    /**
     * Delete a credential owned by the user. Returns true on success.
     */
    public function deleteCredential(User $user, string $credentialId): bool
    {
        return (bool) $user->webAuthnCredentials()->whereKey($credentialId)->delete();
    }

    /**
     * Remove all passkeys for a user (admin recovery action).
     */
    public function clearAllCredentials(User $user): int
    {
        return $user->webAuthnCredentials()->delete();
    }
}
