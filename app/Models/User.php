<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Services\S3Service;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Attribute types for static analysis.
 *
 * These are not decoration. Larastan resolves model property types from the `$casts` *property*
 * and falls back to the migration's column type when it cannot find one — and this model declares
 * its casts in the Laravel 11+ `casts()` **method** form, which it does not read. So without these,
 * `preferences` reads as the migration's `json` column (`string`, hence "cannot unset offset on
 * string") and every `datetime` cast reads as `string` (hence "cannot call format() on string").
 * Annotating here fixes every consumer at once; annotating at the call sites would not.
 *
 * @property array<string, mixed>|null $preferences
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $jwt_secret_generated_at
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $last_passkey_used_at
 * @property string|null $jwt_secret
 * @property string|null $two_factor_secret
 * @property array<int, string>|null $two_factor_recovery_codes
 */
class User extends Authenticatable implements PasskeyUser
{
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable;

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
        'last_passkey_used_at',
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
            'last_login_at' => 'datetime',
            'last_passkey_used_at' => 'datetime',
        ];
    }

    /**
     * Get all assets uploaded by this user
     *
     * @return HasMany<Asset, $this>
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
        $globalRoot = S3Service::getRootFolder();

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
        $globalRoot = S3Service::getRootFolder();

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

        return (int) Setting::get('items_per_page', 24);
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

    /**
     * Check if user can register passkeys
     * Same role gate as 2FA: admins and editors only (not API users)
     */
    public function canEnablePasskeys(): bool
    {
        return $this->isAdmin() || $this->isEditor();
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
