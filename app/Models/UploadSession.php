<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadSession extends Model
{
    protected $fillable = [
        'upload_id',
        'session_token',
        'filename',
        'mime_type',
        'file_size',
        's3_key',
        'staging_s3_key',
        'chunk_size',
        'total_chunks',
        'uploaded_chunks',
        'part_etags',
        'status',
        'user_id',
        'last_activity_at',
    ];

    protected $casts = [
        'part_etags' => 'array',
        'last_activity_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The key the S3 multipart upload lives at: the staging key for a chunked SVG, otherwise the
     * asset's own key (including sessions created before staging keys existed).
     */
    public function multipartKey(): string
    {
        return $this->staging_s3_key ?? $this->s3_key;
    }

    public function isComplete(): bool
    {
        return $this->uploaded_chunks === $this->total_chunks;
    }

    public function progressPercentage(): float
    {
        if ($this->total_chunks === 0) {
            return 0;
        }

        return ($this->uploaded_chunks / $this->total_chunks) * 100;
    }
}
