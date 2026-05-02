<?php

namespace App\Models;

use App\Services\AssetSearchParser;
use App\Services\S3Service;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

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
        'parent_id',
    ];

    protected $casts = [
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'license_expiry_date' => 'date',
        's3_missing_at' => 'datetime',
    ];

    public const APPEND_FIELDS = [
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
     * The asset this one was derived from (e.g. the .tex template that produced this render).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'parent_id');
    }

    /**
     * Assets derived from this one.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Asset::class, 'parent_id')->orderBy('created_at');
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
        return $this->belongsToMany(Tag::class)->withPivot('attached_by')->withTimestamps()
            ->orderByRaw("CASE WHEN tags.type = 'user' THEN 0 WHEN tags.type = 'reference' THEN 1 ELSE 2 END");
    }

    /**
     * Sync tags with attribution, using "last attacher wins" semantics.
     */
    public function syncTagsWithAttribution(array $tagIds, string $attachedBy): void
    {
        if (empty($tagIds)) {
            return;
        }

        $pivotData = array_fill_keys($tagIds, ['attached_by' => $attachedBy]);
        $this->tags()->syncWithoutDetaching($pivotData);

        // Bulk update all existing pivots in one query instead of N individual updates
        DB::table('asset_tag')
            ->where('asset_id', $this->id)
            ->whereIn('tag_id', $tagIds)
            ->update(['attached_by' => $attachedBy]);
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
            if ($this->isSvg()) {
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

        return implode('/', $parts) ?: S3Service::getRootFolder();
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
     * Check if asset is an SVG file
     */
    public function isSvg(): bool
    {
        if ($this->mime_type === 'image/svg+xml') {
            return true;
        }
        $extension = strtolower(pathinfo($this->s3_key, PATHINFO_EXTENSION));

        return $extension === 'svg';
    }

    /**
     * Check if asset is an image
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/') && ! $this->isEps() && ! $this->isSvg();
    }

    /**
     * Check if asset is a video
     */
    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function isMathMl(): bool
    {
        if ($this->mime_type === 'application/mathml+xml') {
            return true;
        }

        return strtolower(pathinfo($this->s3_key, PATHINFO_EXTENSION)) === 'mml';
    }

    public function isTex(): bool
    {
        if (in_array($this->mime_type, ['text/x-tex', 'application/x-tex'])) {
            return true;
        }

        return strtolower(pathinfo($this->s3_key, PATHINFO_EXTENSION)) === 'tex';
    }

    private static array $mimeIcons = [
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
        'video/3gpp' => 'fa-file-video',
        'audio/mpeg' => 'fa-file-audio',
        'audio/wav' => 'fa-file-audio',
        'audio/ogg' => 'fa-file-audio',
        'image/svg+xml' => 'fa-file-image',
        'application/postscript' => 'fa-file-image',
        'application/eps' => 'fa-file-image',
        'image/x-eps' => 'fa-file-image',
        'image/eps' => 'fa-file-image',
        'text/x-tex' => 'fa-file-code',
        'application/x-tex' => 'fa-file-code',
    ];

    private static array $extensionIcons = [
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
        '3gp' => 'fa-file-video',
        'mp3' => 'fa-file-audio',
        'wav' => 'fa-file-audio',
        'svg' => 'fa-file-image',
        'eps' => 'fa-file-image',
        'tex' => 'fa-file-tex',
    ];

    /**
     * Get the appropriate Font Awesome icon for this file type
     */
    public function getFileIcon(): string
    {
        if (isset(self::$mimeIcons[$this->mime_type])) {
            return self::$mimeIcons[$this->mime_type];
        }

        $extension = strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));

        return self::$extensionIcons[$extension] ?? 'fa-file';
    }

    /**
     * Get the Tailwind color class for this asset's file type icon
     */
    public function getIconColorClass(): string
    {
        return match ($this->getFileIcon()) {
            'fa-file-pdf' => 'text-red-500',
            'fa-file-word' => 'text-blue-600',
            'fa-file-excel' => 'text-green-600',
            'fa-file-powerpoint' => 'text-orange-500',
            'fa-file-zipper' => 'text-yellow-600',
            'fa-file-code' => 'text-purple-600',
            'fa-file-video' => 'text-pink-600',
            'fa-file-audio' => 'text-indigo-600',
            'fa-file-csv' => 'text-teal-600',
            'fa-file-lines' => 'text-gray-500',
            'fa-file-image' => 'text-emerald-600',
            'fa-file-tex' => 'text-cyan-600',
            default => 'text-gray-400',
        };
    }

    /**
     * Get all license types with their translated labels
     */
    public static function licenseTypes(): array
    {
        return [
            'public_domain' => __('Public Domain'),
            'cc0' => __('CC0 (No Rights Reserved)'),
            'cc_by' => __('CC BY (Attribution)'),
            'cc_by_sa' => __('CC BY-SA (Attribution-ShareAlike)'),
            'cc_by_nd' => __('CC BY-ND (Attribution-NoDerivs)'),
            'cc_by_nc' => __('CC BY-NC (Attribution-NonCommercial)'),
            'cc_by_nc_sa' => __('CC BY-NC-SA (Attribution-NonCommercial-ShareAlike)'),
            'cc_by_nc_nd' => __('CC BY-NC-ND (Attribution-NonCommercial-NoDerivs)'),
            'fair_use' => __('Fair Use'),
            'all_rights_reserved' => __('All Rights Reserved'),
            'other' => __('Other'),
        ];
    }

    /**
     * Get the translated label for this asset's license type
     */
    public function getLicenseLabel(): string
    {
        if (! $this->license_type) {
            return '';
        }

        return static::licenseTypes()[$this->license_type] ?? $this->license_type;
    }

    /**
     * Scope: Search assets. Operator parsing lives in AssetSearchParser.
     */
    public function scopeSearch($query, ?string $search)
    {
        AssetSearchParser::apply($query, $search);

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

    private static array $typeCategories = [
        'document' => ['application', 'text'],
        'image' => ['image'],
        'video' => ['video'],
        'audio' => ['audio'],
    ];

    /**
     * Scope: Filter by mime type
     */
    public function scopeOfType($query, ?string $type)
    {
        if (! $type) {
            return $query;
        }

        $aliases = ['images' => 'image', 'videos' => 'video', 'documents' => 'document'];
        $category = $aliases[strtolower($type)] ?? strtolower($type);

        $prefixes = self::$typeCategories[$category] ?? null;
        if (! $prefixes) {
            return $query;
        }

        return $query->where(function ($q) use ($prefixes) {
            foreach ($prefixes as $prefix) {
                $q->orWhere('mime_type', 'like', "{$prefix}/%");
            }
        });
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
