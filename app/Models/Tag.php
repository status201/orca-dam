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
        return $this->belongsToMany(Asset::class)->withPivot('attached_by')->withTimestamps();
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
     * Resolve an array of tag names to tag IDs, creating missing tags with the given type.
     *
     * Note: Tag names are unique. If a tag already exists with a different type, the existing
     * tag is reused — its type is NOT changed.
     */
    private static function resolveTagIds(array $names, string $type): array
    {
        $normalized = collect($names)
            ->map(fn ($n) => strtolower(trim($n)))
            ->filter(fn ($n) => $n !== '')
            ->unique()
            ->values();

        if ($normalized->isEmpty()) {
            return [];
        }

        // Batch lookup: 1 query for all existing tags
        $existing = static::whereIn('name', $normalized)->pluck('id', 'name');

        // Create only the missing ones
        foreach ($normalized->diff($existing->keys()) as $name) {
            $tag = static::create(['name' => $name, 'type' => $type]);
            $existing[$name] = $tag->id;
        }

        return $normalized->map(fn ($n) => $existing[$n])->all();
    }

    /**
     * Resolve an array of tag names to tag IDs, creating missing tags as 'user' type.
     *
     * Note: Tag names are unique. If a tag already exists with a different type (e.g. 'ai'
     * or 'reference'), the existing tag is reused — its type is NOT changed to 'user'.
     */
    public static function resolveUserTagIds(array $names): array
    {
        return self::resolveTagIds($names, 'user');
    }

    /**
     * Resolve an array of tag names to tag IDs, creating missing tags as 'reference' type.
     *
     * Note: Tag names are unique. If a tag already exists with a different type (e.g. 'user'
     * or 'ai'), the existing tag is reused — its type is NOT changed to 'reference'.
     */
    public static function resolveReferenceTagIds(array $names): array
    {
        return self::resolveTagIds($names, 'reference');
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
