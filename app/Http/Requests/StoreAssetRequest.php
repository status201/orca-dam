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
            'folder' => 'nullable|string|max:255',
            'keep_original_filename' => 'nullable|boolean',
        ], $this->uploadMetadataRules());
    }
}
