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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
