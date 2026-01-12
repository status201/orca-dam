<?php

namespace App\Policies;

use App\Models\User;

class SystemPolicy
{
    /**
     * Determine if the user can access system administration.
     */
    public function access(User $user): bool
    {
        return $user->isAdmin();
    }
}
