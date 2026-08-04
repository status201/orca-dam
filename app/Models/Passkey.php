<?php

namespace App\Models;

use Laravel\Passkeys\Passkey as BasePasskey;

/**
 * The package annotates its own `$user` as the `PasskeyUser` *interface*, which is all it can
 * know. ORCA only ever registers `App\Models\User` (AppServiceProvider calls
 * `Passkeys::usePasskeyModel()` alongside the default user model), so narrowing it here is
 * accurate and saves every consumer of `$passkey->user` from re-narrowing.
 *
 * @property-read User $user
 */
class Passkey extends BasePasskey
{
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'credential' => 'encrypted:json',
        ]);
    }
}
