<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
    ];

    protected $casts = [
        'type' => 'string',
    ];

    /**
     * Get all assets with this tag
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class)->withTimestamps();
    }

    /**
     * Scope: User tags only
     */
    public function scopeUserTags($query)
    {
        return $query->where('type', 'user');
    }

    /**
     * Scope: AI tags only
     */
    public function scopeAiTags($query)
    {
        return $query->where('type', 'ai');
    }

    /**
     * Resolve an array of tag names to tag IDs, creating missing tags as 'user' type.
     */
    public static function resolveUserTagIds(array $names): array
    {
        $tagIds = [];
        foreach ($names as $name) {
            $tag = static::firstOrCreate(
                ['name' => strtolower(trim($name))],
                ['type' => 'user']
            );
            $tagIds[] = $tag->id;
        }

        return $tagIds;
    }

    /**
     * Scope: Search tags by name
     */
    public function scopeSearch($query, ?string $search)
    {
        if (! $search) {
            return $query;
        }

        return $query->where('name', 'like', "%{$search}%");
    }
}
