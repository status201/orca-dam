<?php

namespace App\Http\Requests\Concerns;

use App\Models\Asset;
use Illuminate\Validation\Rule;

/**
 * Shared validation rules + accessor for the batch upload-metadata fields
 * (metadata_tags, metadata_reference_tag_ids, metadata_license_type,
 * metadata_copyright, metadata_copyright_source) used by the upload and tool
 * endpoints. Centralised here so the rules live in one place.
 */
trait HasUploadMetadataRules
{
    protected function uploadMetadataRules(): array
    {
        return [
            'metadata_tags' => 'nullable|array',
            'metadata_tags.*' => 'string|max:100',
            'metadata_reference_tag_ids' => 'nullable|array',
            'metadata_reference_tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(fn ($q) => $q->where('type', 'reference')),
            ],
            'metadata_license_type' => ['nullable', 'string', Rule::in(array_keys(Asset::licenseTypes()))],
            'metadata_copyright' => 'nullable|string|max:500',
            'metadata_copyright_source' => 'nullable|string|max:500',
        ];
    }

    /**
     * The batch metadata payload, shaped for AssetProcessingService::applyUploadMetadata().
     */
    public function uploadMetadata(): array
    {
        return [
            'tags' => $this->input('metadata_tags'),
            'license_type' => $this->input('metadata_license_type'),
            'copyright' => $this->input('metadata_copyright'),
            'copyright_source' => $this->input('metadata_copyright_source'),
            'reference_tag_ids' => $this->input('metadata_reference_tag_ids'),
        ];
    }
}
