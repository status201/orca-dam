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
        return !empty($this->jwt_secret);
    }

    /**
     * Check if user can manage all assets
     */
    public function canManageAllAssets(): bool
    {
        return $this->isAdmin();
    }
}
