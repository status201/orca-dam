<?php

namespace App\Listeners;

use App\Models\User;
use Laravel\Passkeys\Events\PasskeyVerified;

class TouchPasskeyLastUsed
{
    /**
     * Stamp last_passkey_used_at on the user after a successful passkey assertion.
     *
     * The package itself maintains passkeys.last_used_at inside VerifyPasskey;
     * we only need to mirror the user-level timestamp.
     */
    public function handle(PasskeyVerified $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill(['last_passkey_used_at' => now()])->saveQuietly();
    }
}
