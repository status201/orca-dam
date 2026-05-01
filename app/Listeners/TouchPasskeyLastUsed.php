<?php

namespace App\Listeners;

use App\Models\User;
use Laragear\WebAuthn\Events\CredentialAsserted;

class TouchPasskeyLastUsed
{
    /**
     * Stamp last_used_at on the credential and last_passkey_used_at on the user
     * after a successful passkey assertion.
     */
    public function handle(CredentialAsserted $event): void
    {
        $now = now();

        $event->credential->forceFill(['last_used_at' => $now])->saveQuietly();

        if ($event->user instanceof User) {
            $event->user->forceFill(['last_passkey_used_at' => $now])->saveQuietly();
        }
    }
}
