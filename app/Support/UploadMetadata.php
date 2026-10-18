<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The batch metadata an upload carries (the `metadata_*` fields), as one value instead of seven
 * positional parameters. Validated upstream by UploadMetadataRules; consumed by
 * AssetProcessingService::applyUploadMetadata().
 *
 * @see specs/features/asset-upload.md
 */
final class UploadMetadata
{
    /**
     * @param  array<int, string>|null  $tags  raw tag input — applyUploadMetadata() comma-splits it
     * @param  array<int, int|string>|null  $referenceTagIds
     */
    public function __construct(
        public readonly ?array $tags = null,
        public readonly ?array $referenceTagIds = null,
        public readonly ?string $licenseType = null,
        public readonly ?string $copyright = null,
        public readonly ?string $copyrightSource = null,
        public readonly ?string $dateObtained = null,
    ) {}

    /**
     * Read the `metadata_*` fields shared by the web upload, chunked completion and the tool endpoints.
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            tags: $request->input('metadata_tags'),
            referenceTagIds: $request->input('metadata_reference_tag_ids'),
            licenseType: $request->input('metadata_license_type'),
            copyright: $request->input('metadata_copyright'),
            copyrightSource: $request->input('metadata_copyright_source'),
            dateObtained: $request->input('metadata_date_obtained'),
        );
    }
}
