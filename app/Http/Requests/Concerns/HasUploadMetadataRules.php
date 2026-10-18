<?php

namespace App\Http\Requests\Concerns;

use App\Support\UploadMetadata;
use App\Support\UploadMetadataRules;

/**
 * The batch upload-metadata rules + accessor for the FormRequests that carry them
 * (metadata_tags, metadata_reference_tag_ids, metadata_license_type, metadata_copyright,
 * metadata_copyright_source, metadata_date_obtained).
 *
 * The rules themselves live in App\Support\UploadMetadataRules, because
 * ChunkedUploadController::complete needs the same set but is not a FormRequest — it validates
 * inline so its authorize() still runs first. This trait adds only the request-shaped accessor.
 */
trait HasUploadMetadataRules
{
    protected function uploadMetadataRules(): array
    {
        return UploadMetadataRules::rules();
    }

    /**
     * The batch metadata payload, for AssetProcessingService::applyUploadMetadata().
     */
    public function uploadMetadata(): UploadMetadata
    {
        return UploadMetadata::fromRequest($this);
    }
}
