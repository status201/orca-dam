<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasUploadMetadataRules;
use App\Rules\AllowedUploadExtension;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssetRequest extends FormRequest
{
    use HasUploadMetadataRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'files.*' => ['required', 'file', 'max:512000', new AllowedUploadExtension], // 500MB max
            // 100, matching FolderController's creation cap. folder + filename both feed the
            // varchar(255) s3_key, and S3Service's derived keys (thumbnails/L/...) are longer
            // still — see input-validation.md's open question on the derived-key budget.
            'folder' => 'nullable|string|max:100',
            'keep_original_filename' => 'nullable|boolean',
        ], $this->uploadMetadataRules());
    }
}
