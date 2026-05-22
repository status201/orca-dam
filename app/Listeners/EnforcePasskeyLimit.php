<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\PasskeyService;
use Laravel\Passkeys\Events\PasskeyRegistered;

class EnforcePasskeyLimit
{
    /**
     * Safety net for the per-user cap. The PasskeyController gates the options
     * endpoint up front, but a concurrent registration could squeak past — so
     * after a passkey is persisted, delete it again if the user is over the cap.
     *
     * Pairs with the pre-flight check in PasskeyController::store, not a
     * replacement for it (deleting an already-stored credential is a worse UX).
     */
    public function handle(PasskeyRegistered $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        if ($event->user->passkeys()->count() <= PasskeyService::MAX_CREDENTIALS_PER_USER) {
            return;
        }

        $event->passkey->delete();
    }
}
