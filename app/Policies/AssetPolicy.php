<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\Setting;
use App\Models\User;

class AssetPolicy
{
    /**
     * Roles that have any access to assets at all.
     *
     * Listing roles explicitly (rather than `return true`) means a future
     * role addition has to opt in to each ability instead of inheriting it.
     */
    private function isKnownRole(User $user): bool
    {
        return $user->isAdmin() || $user->isEditor() || $user->isApiUser();
    }

    /**
     * Determine whether the user can view any assets.
     */
    public function viewAny(User $user): bool
    {
        return $this->isKnownRole($user);
    }

    /**
     * Determine whether the user can view the asset.
     */
    public function view(User $user, Asset $asset): bool
    {
        return $this->isKnownRole($user);
    }

    /**
     * Determine whether the user can create assets.
     */
    public function create(User $user): bool
    {
        return $this->isKnownRole($user);
    }

    /**
     * Determine whether the user can replace assets.
     */
    public function replace(User $user): bool
    {
        return $user->isAdmin() || $user->isEditor();
    }

    /**
     * Determine whether the user can update the asset.
     */
    public function update(User $user, Asset $asset): bool
    {
        return $this->isKnownRole($user);
    }

    /**
     * Determine whether the user can delete the asset.
     */
    public function delete(User $user, Asset $asset): bool
    {
        return $user->isAdmin() || $user->isEditor();
    }

    /**
     * Determine whether the user can restore the asset.
     */
    public function restore(User $user, Asset|string|null $asset = null): bool
    {
        return $user->isAdmin() || $user->isEditor();
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

    /**
     * Determine whether the user can move assets between folders.
     */
    public function move(User $user): bool
    {
        return $user->isAdmin() && (bool) Setting::get('maintenance_mode', false);
    }

    /**
     * Determine whether the user can bulk permanently delete assets.
     */
    public function bulkForceDelete(User $user): bool
    {
        return $user->isAdmin() && (bool) Setting::get('maintenance_mode', false);
    }

    /**
     * Determine whether the user can bulk trash assets.
     */
    public function bulkTrash(User $user): bool
    {
        return $user->isAdmin() || $user->isEditor();
    }

    /**
     * Determine whether the user can bulk restore assets from trash.
     */
    public function bulkRestore(User $user): bool
    {
        return $user->isAdmin() || $user->isEditor();
    }

    /**
     * Determine whether the user can bulk download assets.
     */
    public function bulkDownload(User $user): bool
    {
        return $this->isKnownRole($user);
    }
}
