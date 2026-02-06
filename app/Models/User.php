<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'jwt_secret',
        'jwt_secret_generated_at',
        'preferences',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'jwt_secret',
        'email',
        'email_verified_at',
        'jwt_secret_generated_at',
        'preferences',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'jwt_secret_generated_at' => 'datetime',
            'jwt_secret' => 'encrypted',
            'preferences' => 'array',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Get all assets uploaded by this user
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * Check if user is an admin
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user is an editor
     */
    public function isEditor(): bool
    {
        return $this->role === 'editor';
    }

    /**
     * Check if user is an API user
     */
    public function isApiUser(): bool
    {
        return $this->role === 'api';
    }

    /**
     * Check if user has a JWT secret configured
     */
    public function hasJwtSecret(): bool
    {
        return ! empty($this->jwt_secret);
    }

    /**
     * Check if user can manage all assets
     */
    public function canManageAllAssets(): bool
    {
        return $this->isAdmin();
    }

    /**
     * Get a user preference value
     */
    public function getPreference(string $key, mixed $default = null): mixed
    {
        return data_get($this->preferences, $key, $default);
    }

    /**
     * Set a user preference value
     */
    public function setPreference(string $key, mixed $value): bool
    {
        $preferences = $this->preferences ?? [];
        $preferences[$key] = $value;
        $this->preferences = $preferences;

        return $this->save();
    }

    /**
     * Get the user's home folder preference, validated against global root
     */
    public function getHomeFolder(): string
    {
        $userFolder = $this->getPreference('home_folder');
        $globalRoot = \App\Services\S3Service::getRootFolder();

        if ($userFolder && $this->isValidHomeFolder($userFolder)) {
            return $userFolder;
        }

        return $globalRoot;
    }

    /**
     * Check if a folder is a valid home folder (within global root)
     */
    public function isValidHomeFolder(string $folder): bool
    {
        $globalRoot = \App\Services\S3Service::getRootFolder();

        // If no global root configured, any folder is valid
        if ($globalRoot === '') {
            return true;
        }

        // Folder must be the root or start with root/
        return $folder === $globalRoot || str_starts_with($folder, $globalRoot.'/');
    }

    /**
     * Get the user's items per page preference, falling back to global setting
     */
    public function getItemsPerPage(): int
    {
        $userPref = $this->getPreference('items_per_page');
        if ($userPref && (int) $userPref > 0) {
            return (int) $userPref;
        }

        return (int) \App\Models\Setting::get('items_per_page', 24);
    }

    /**
     * Check if user has two-factor authentication enabled
     */
    public function hasTwoFactorEnabled(): bool
    {
        return ! empty($this->two_factor_secret) && ! empty($this->two_factor_confirmed_at);
    }

    /**
     * Check if user can enable two-factor authentication
     * Only admins and editors can enable 2FA (not API users)
     */
    public function canEnableTwoFactor(): bool
    {
        return $this->isAdmin() || $this->isEditor();
    }
}
