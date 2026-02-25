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
     * Scope: Reference tags only
     */
    public function scopeReferenceTags($query)
    {
        return $query->where('type', 'reference');
    }

    /**
     * Resolve an array of tag names to tag IDs, creating missing tags as 'user' type.
     *
     * Note: Tag names are unique. If a tag already exists with a different type (e.g. 'ai'
     * or 'reference'), the existing tag is reused — its type is NOT changed to 'user'.
     */
    public static function resolveUserTagIds(array $names): array
    {
        $tagIds = [];
        foreach ($names as $name) {
            $tag = static::firstOrNew(['name' => strtolower(trim($name))]);
            if (! $tag->exists) {
                $tag->type = 'user';
                $tag->save();
            }
            $tagIds[] = $tag->id;
        }

        return $tagIds;
    }

    /**
     * Resolve an array of tag names to tag IDs, creating missing tags as 'reference' type.
     *
     * Note: Tag names are unique. If a tag already exists with a different type (e.g. 'user'
     * or 'ai'), the existing tag is reused — its type is NOT changed to 'reference'.
     */
    public static function resolveReferenceTagIds(array $names): array
    {
        $tagIds = [];
        foreach ($names as $name) {
            $tag = static::firstOrNew(['name' => strtolower(trim($name))]);
            if (! $tag->exists) {
                $tag->type = 'reference';
                $tag->save();
            }
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
