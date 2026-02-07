<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;

class AssetPolicy
{
    /**
     * Determine whether the user can view any assets.
     */
    public function viewAny(User $user): bool
    {
        return true; // All authenticated users can view assets
    }

    /**
     * Determine whether the user can view the asset.
     */
    public function view(User $user, Asset $asset): bool
    {
        return true; // All authenticated users can view individual assets
    }

    /**
     * Determine whether the user can create assets.
     */
    public function create(User $user): bool
    {
        return true; // All authenticated users can upload
    }

    /**
     * Determine whether the user can replace assets.
     */
    public function replace(User $user): bool
    {
        // API users cannot replace assets
        if ($user->isApiUser()) {
            return false;
        }

        // All other authenticated users can replace any asset
        return true;
    }

    /**
     * Determine whether the user can update the asset.
     */
    public function update(User $user, Asset $asset): bool
    {
        // All authenticated users can update any asset
        return true;
    }

    /**
     * Determine whether the user can delete the asset.
     */
    public function delete(User $user, Asset $asset): bool
    {
        // API users cannot delete assets
        if ($user->isApiUser()) {
            return false;
        }

        // All other authenticated users can delete any asset
        return true;
    }

    /**
     * Determine whether the user can restore the asset.
     */
    public function restore(User $user, Asset|string|null $asset = null): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can permanently delete the asset.
     */
    public function forceDelete(User $user, Asset|string|null $asset = null): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can discover unmapped assets.
     */
    public function discover(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can export assets.
     */
    public function export(User $user): bool
    {
        return $user->isAdmin();
    }
}
