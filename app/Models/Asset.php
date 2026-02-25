<?php

namespace App\Models;

use App\Services\S3Service;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'resize_s_s3_key',
        'resize_m_s3_key',
        'resize_l_s3_key',
        'alt_text',
        'caption',
        'license_type',
        'license_expiry_date',
        'copyright',
        'copyright_source',
        's3_missing_at',
        'user_id',
        'last_modified_by',
    ];

    protected $casts = [
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'license_expiry_date' => 'date',
        's3_missing_at' => 'datetime',
    ];

    protected $appends = [
        'url',
        'thumbnail_url',
        'resize_s_url',
        'resize_m_url',
        'resize_l_url',
        'formatted_size',
        'folder',
    ];

    /**
     * Get the user who uploaded this asset
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who last modified this asset
     */
    public function modifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_modified_by');
    }

    /**
     * Check if the asset has been modified after creation
     */
    public function wasModified(): bool
    {
        return $this->updated_at->gt($this->created_at);
    }

    /**
     * Get all tags for this asset
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withTimestamps()
            ->orderByRaw("CASE WHEN tags.type = 'user' THEN 0 WHEN tags.type = 'reference' THEN 1 ELSE 2 END");
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
     * Get only reference tags
     */
    public function referenceTags(): BelongsToMany
    {
        return $this->tags()->where('type', 'reference');
    }

    /**
     * Get the full S3 URL
     */
    public function getUrlAttribute(): string
    {
        return S3Service::getPublicBaseUrl().'/'.$this->s3_key;
    }

    /**
     * Get the thumbnail URL
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        if (! $this->thumbnail_s3_key) {
            // For GIFs without thumbnails, use the original
            if ($this->mime_type === 'image/gif') {
                return $this->url;
            }

            return null;
        }

        return S3Service::getPublicBaseUrl().'/'.$this->thumbnail_s3_key;
    }

    /**
     * Get the small resize URL
     */
    public function getResizeSUrlAttribute(): ?string
    {
        if (! $this->resize_s_s3_key) {
            return null;
        }

        return S3Service::getPublicBaseUrl().'/'.$this->resize_s_s3_key;
    }

    /**
     * Get the medium resize URL
     */
    public function getResizeMUrlAttribute(): ?string
    {
        if (! $this->resize_m_s3_key) {
            return null;
        }

        return S3Service::getPublicBaseUrl().'/'.$this->resize_m_s3_key;
    }

    /**
     * Get the large resize URL
     */
    public function getResizeLUrlAttribute(): ?string
    {
        if (! $this->resize_l_s3_key) {
            return null;
        }

        return S3Service::getPublicBaseUrl().'/'.$this->resize_l_s3_key;
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

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Get the folder path from s3_key
     * e.g., assets/marketing/uuid.jpg -> assets/marketing
     */
    public function getFolderAttribute(): string
    {
        $parts = explode('/', $this->s3_key);
        array_pop($parts); // Remove filename

        return implode('/', $parts) ?: \App\Services\S3Service::getRootFolder();
    }

    /**
     * Check if asset is an EPS file
     */
    public function isEps(): bool
    {
        $epsMimeTypes = [
            'application/postscript',
            'application/eps',
            'image/x-eps',
            'image/eps',
        ];

        if (in_array($this->mime_type, $epsMimeTypes)) {
            return true;
        }

        $extension = strtolower(pathinfo($this->s3_key, PATHINFO_EXTENSION));

        return $extension === 'eps';
    }

    /**
     * Check if asset is an image
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/') && ! $this->isEps();
    }

    /**
     * Check if asset is a video
     */
    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
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
            'application/postscript' => 'fa-file-image',
            'application/eps' => 'fa-file-image',
            'image/x-eps' => 'fa-file-image',
            'image/eps' => 'fa-file-image',
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
            'eps' => 'fa-file-image',
        ];

        return $extensionIcons[$extension] ?? 'fa-file';
    }

    /**
     * Strip known URL prefixes from a search term so it matches S3 keys.
     */
    private static function normalizeSearchTerm(string $search): string
    {
        // Strip the S3 bucket URL prefix (e.g. "https://bucket.s3.amazonaws.com/")
        $s3Url = rtrim(config('filesystems.disks.s3.url'), '/').'/';
        if (str_starts_with($search, $s3Url)) {
            return substr($search, strlen($s3Url));
        }

        // Strip the custom domain prefix if configured (e.g. "https://cdn.example.com/")
        $customDomain = Setting::get('custom_domain', '');
        if ($customDomain !== '' && $customDomain !== null) {
            $customUrl = rtrim($customDomain, '/').'/';
            if (str_starts_with($search, $customUrl)) {
                return substr($search, strlen($customUrl));
            }
        }

        return $search;
    }

    /**
     * Parse search string into regular, required (+), and excluded (-) terms.
     */
    private static function parseSearchTerms(string $search): array
    {
        $terms = ['regular' => [], 'required' => [], 'excluded' => []];

        foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            if (str_starts_with($token, '+') && strlen($token) > 1) {
                $terms['required'][] = substr($token, 1);
            } elseif (str_starts_with($token, '-') && strlen($token) > 1) {
                $terms['excluded'][] = substr($token, 1);
            } elseif ($token !== '+' && $token !== '-') {
                $terms['regular'][] = $token;
            }
        }

        return $terms;
    }

    /**
     * Add OR search condition across all searchable fields for a single term.
     */
    private static function addSearchCondition($query, string $term): void
    {
        $query->where('filename', 'like', "%{$term}%")
            ->orWhere('s3_key', 'like', "%{$term}%")
            ->orWhere('alt_text', 'like', "%{$term}%")
            ->orWhere('caption', 'like', "%{$term}%")
            ->orWhereHas('tags', function ($tagQuery) use ($term) {
                $tagQuery->where('name', 'like', "%{$term}%");
            });
    }

    /**
     * Add exclusion condition: asset must NOT match term in any field.
     */
    private static function addExcludeCondition($query, string $term): void
    {
        $query->where('filename', 'not like', "%{$term}%")
            ->where('s3_key', 'not like', "%{$term}%")
            ->where(function ($q) use ($term) {
                $q->whereNull('alt_text')->orWhere('alt_text', 'not like', "%{$term}%");
            })
            ->where(function ($q) use ($term) {
                $q->whereNull('caption')->orWhere('caption', 'not like', "%{$term}%");
            })
            ->whereDoesntHave('tags', function ($tagQuery) use ($term) {
                $tagQuery->where('name', 'like', "%{$term}%");
            });
    }

    /**
     * Scope: Search assets
     */
    public function scopeSearch($query, ?string $search)
    {
        if (! $search) {
            return $query;
        }

        $normalized = self::normalizeSearchTerm($search);

        // If the search was a URL (normalizeSearchTerm changed it), treat as single literal term
        if ($normalized !== $search) {
            return $query->where(function ($q) use ($normalized) {
                self::addSearchCondition($q, $normalized);
            });
        }

        $terms = self::parseSearchTerms($normalized);

        // Regular terms: at least one must match (OR within a single where group)
        if (! empty($terms['regular'])) {
            $query->where(function ($q) use ($terms) {
                foreach ($terms['regular'] as $term) {
                    $q->orWhere(function ($sub) use ($term) {
                        self::addSearchCondition($sub, $term);
                    });
                }
            });
        }

        // Required terms: each must match in at least one field (AND)
        foreach ($terms['required'] as $term) {
            $query->where(function ($q) use ($term) {
                self::addSearchCondition($q, $term);
            });
        }

        // Excluded terms: must NOT match in any field
        foreach ($terms['excluded'] as $term) {
            $query->where(function ($q) use ($term) {
                self::addExcludeCondition($q, $term);
            });
        }

        return $query;
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
        if (! $type) {
            return $query;
        }

        return $query->where('mime_type', 'like', "{$type}/%");
    }

    /**
     * Scope: Filter by user
     */
    public function scopeByUser($query, ?int $userId)
    {
        if (! $userId) {
            return $query;
        }

        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Filter by folder
     */
    public function scopeInFolder($query, ?string $folder)
    {
        if (! $folder) {
            return $query;
        }

        return $query->where('s3_key', 'like', rtrim($folder, '/').'/%');
    }

    /**
     * Scope: Apply sorting
     */
    public function scopeApplySort($query, string $sort = 'date_desc')
    {
        return match ($sort) {
            'date_asc' => $query->oldest('updated_at'),
            'upload_asc' => $query->oldest('created_at'),
            'upload_desc' => $query->latest('created_at'),
            'size_asc' => $query->orderBy('size', 'asc'),
            'size_desc' => $query->orderBy('size', 'desc'),
            'name_asc' => $query->orderBy('filename', 'asc'),
            'name_desc' => $query->orderBy('filename', 'desc'),
            's3key_asc' => $query->orderBy('s3_key', 'asc'),
            's3key_desc' => $query->orderBy('s3_key', 'desc'),
            default => $query->latest('updated_at'), // date_desc
        };
    }

    /**
     * Scope: Filter assets with missing S3 objects
     */
    public function scopeMissing($query)
    {
        return $query->whereNotNull('s3_missing_at');
    }

    /**
     * Check if the asset's S3 object is missing
     */
    public function getIsMissingAttribute(): bool
    {
        return $this->s3_missing_at !== null;
    }
}
