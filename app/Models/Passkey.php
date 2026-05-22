<?php

namespace App\Models;

use Laravel\Passkeys\Passkey as BasePasskey;

class Passkey extends BasePasskey
{
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'credential' => 'encrypted:json',
        ]);
    }
}
