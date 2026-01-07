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
        'etag',
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
            // For GIFs without thumbnails, use the original
            if ($this->mime_type === 'image/gif') {
                return $this->url;
            }
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
     * Get the appropriate Font Awesome icon for this file type
     */
    public function getFileIcon(): string
    {
        // Check by mime type first
        $icons = [
            'application/pdf' => 'fa-file-pdf',
            'application/msword' => 'fa-file-word',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'fa-file-word',
            'application/vnd.ms-excel' => 'fa-file-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'fa-file-excel',
            'application/vnd.ms-powerpoint' => 'fa-file-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'fa-file-powerpoint',
            'application/zip' => 'fa-file-zipper',
            'application/x-zip-compressed' => 'fa-file-zipper',
            'application/x-rar-compressed' => 'fa-file-zipper',
            'application/x-7z-compressed' => 'fa-file-zipper',
            'text/plain' => 'fa-file-lines',
            'text/csv' => 'fa-file-csv',
            'application/json' => 'fa-file-code',
            'text/html' => 'fa-file-code',
            'text/css' => 'fa-file-code',
            'text/javascript' => 'fa-file-code',
            'application/javascript' => 'fa-file-code',
            'video/mp4' => 'fa-file-video',
            'video/mpeg' => 'fa-file-video',
            'video/quicktime' => 'fa-file-video',
            'video/x-msvideo' => 'fa-file-video',
            'audio/mpeg' => 'fa-file-audio',
            'audio/wav' => 'fa-file-audio',
            'audio/ogg' => 'fa-file-audio',
        ];

        if (isset($icons[$this->mime_type])) {
            return $icons[$this->mime_type];
        }

        // Check by file extension as fallback
        $extension = strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));
        $extensionIcons = [
            'pdf' => 'fa-file-pdf',
            'doc' => 'fa-file-word',
            'docx' => 'fa-file-word',
            'xls' => 'fa-file-excel',
            'xlsx' => 'fa-file-excel',
            'ppt' => 'fa-file-powerpoint',
            'pptx' => 'fa-file-powerpoint',
            'zip' => 'fa-file-zipper',
            'rar' => 'fa-file-zipper',
            '7z' => 'fa-file-zipper',
            'txt' => 'fa-file-lines',
            'csv' => 'fa-file-csv',
            'json' => 'fa-file-code',
            'html' => 'fa-file-code',
            'css' => 'fa-file-code',
            'js' => 'fa-file-code',
            'mp4' => 'fa-file-video',
            'mov' => 'fa-file-video',
            'avi' => 'fa-file-video',
            'mp3' => 'fa-file-audio',
            'wav' => 'fa-file-audio',
        ];

        return $extensionIcons[$extension] ?? 'fa-file';
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
