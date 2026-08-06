<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\Tag;
use Illuminate\Validation\Rule;

/**
 * The validation rules for the batch upload-metadata fields, in one place.
 *
 * A plain class rather than only a trait because `ChunkedUploadController::complete` needs the
 * same rules but is not a FormRequest — it validates inline so that its `authorize()` still runs
 * first. It used to hold a hand-copied duplicate of the array instead, and that copy is where the
 * copyright cap drifted 245 characters past its column.
 *
 * `HasUploadMetadataRules` delegates here and adds the FormRequest-only accessor.
 *
 * @see specs/features/input-validation.md REQ-4
 * @see specs/features/asset-upload.md REQ-5
 */
final class UploadMetadataRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'metadata_tags' => 'nullable|array',
            'metadata_tags.*' => 'string|max:'.Tag::MAX_NAME_LENGTH,
            'metadata_reference_tag_ids' => 'nullable|array',
            'metadata_reference_tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(fn ($q) => $q->where('type', 'reference')),
            ],
            'metadata_license_type' => ['nullable', 'string', Rule::in(array_keys(Asset::licenseTypes()))],
            // From ColumnLimits, never a literal — these two rules allowed 500 against a
            // varchar(255) column, which is how an over-length copyright became a 500.
            'metadata_copyright' => 'nullable|string|max:'.ColumnLimits::for('assets', 'copyright'),
            'metadata_copyright_source' => 'nullable|string|max:'.ColumnLimits::for('assets', 'copyright_source'),
        ];
    }
}
