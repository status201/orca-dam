<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Asset extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        's3_key',
        'filename',
        'mime_type',
        'size',
        'width',
        'height',
        'thumbnail_s3_key',
        'alt_text',
        'caption',
        'user_id',
    ];

    protected $casts = [
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    protected $appends = [
        'url',
        'thumbnail_url',
        'formatted_size',
    ];

    /**
     * Get the user who uploaded this asset
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all tags for this asset
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withTimestamps();
    }

    /**
     * Get only user-created tags
     */
    public function userTags(): BelongsToMany
    {
        return $this->tags()->where('type', 'user');
    }

    /**
     * Get only AI-generated tags
     */
    public function aiTags(): BelongsToMany
    {
        return $this->tags()->where('type', 'ai');
    }

    /**
     * Get the full S3 URL
     */
    public function getUrlAttribute(): string
    {
        return config('filesystems.disks.s3.url') . '/' . $this->s3_key;
    }

    /**
     * Get the thumbnail URL
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        if (!$this->thumbnail_s3_key) {
            return null;
        }
        
        return config('filesystems.disks.s3.url') . '/' . $this->thumbnail_s3_key;
    }

    /**
     * Get human-readable file size
     */
    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Check if asset is an image
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Scope: Search assets
     */
    public function scopeSearch($query, ?string $search)
    {
        if (!$search) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('filename', 'like', "%{$search}%")
              ->orWhere('alt_text', 'like', "%{$search}%")
              ->orWhere('caption', 'like', "%{$search}%")
              ->orWhereHas('tags', function ($tagQuery) use ($search) {
                  $tagQuery->where('name', 'like', "%{$search}%");
              });
        });
    }

    /**
     * Scope: Filter by tags
     */
    public function scopeWithTags($query, array $tagIds)
    {
        if (empty($tagIds)) {
            return $query;
        }

        return $query->whereHas('tags', function ($q) use ($tagIds) {
            $q->whereIn('tags.id', $tagIds);
        });
    }

    /**
     * Scope: Filter by mime type
     */
    public function scopeOfType($query, ?string $type)
    {
        if (!$type) {
            return $query;
        }

        return $query->where('mime_type', 'like', "{$type}/%");
    }

    /**
     * Scope: Filter by user
     */
    public function scopeByUser($query, ?int $userId)
    {
        if (!$userId) {
            return $query;
        }

        return $query->where('user_id', $userId);
    }
}
