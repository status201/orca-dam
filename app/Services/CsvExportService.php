<?php

namespace App\Services;

use App\Models\Asset;

class CsvExportService
{
    public function generateHeaders(): array
    {
        return [
            'id',
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
            'user_id',
            'user_name',
            'user_email',
            'last_modified_by_id',
            'last_modified_by_name',
            'user_tags',
            'ai_tags',
            'reference_tags',
            'url',
            'thumbnail_url',
            'resize_s_url',
            'resize_m_url',
            'resize_l_url',
            'created_at',
            'updated_at',
        ];
    }

    public function formatRow(Asset $asset): array
    {
        $userTagNames = $asset->tags->where('type', 'user')->pluck('name')->join(', ');
        $aiTagNames = $asset->tags->where('type', 'ai')->pluck('name')->join(', ');
        $referenceTagNames = $asset->tags->where('type', 'reference')->pluck('name')->join(', ');

        return [
            $asset->id,
            $asset->s3_key,
            $asset->filename,
            $asset->mime_type,
            $asset->size,
            $asset->etag,
            $asset->width,
            $asset->height,
            $asset->thumbnail_s3_key,
            $asset->resize_s_s3_key,
            $asset->resize_m_s3_key,
            $asset->resize_l_s3_key,
            $asset->alt_text,
            $asset->caption,
            $asset->license_type,
            $asset->license_expiry_date?->toDateString(),
            $asset->copyright,
            $asset->copyright_source,
            $asset->user_id,
            $asset->user->name ?? '',
            $asset->user->email ?? '',
            $asset->last_modified_by,
            $asset->modifier->name ?? '',
            $userTagNames,
            $aiTagNames,
            $referenceTagNames,
            $asset->url,
            $asset->thumbnail_url,
            $asset->resize_s_url,
            $asset->resize_m_url,
            $asset->resize_l_url,
            $asset->created_at?->toDateTimeString(),
            $asset->updated_at?->toDateTimeString(),
        ];
    }
}
