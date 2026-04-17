<?php

namespace App\Http\Requests;

use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'files.*' => 'required|file|max:512000', // 500MB max
            'folder' => 'nullable|string|max:255',
            'keep_original_filename' => 'nullable|boolean',
            'metadata_tags' => 'nullable|array',
            'metadata_tags.*' => 'string|max:100',
            'metadata_license_type' => ['nullable', 'string', Rule::in(array_keys(Asset::licenseTypes()))],
            'metadata_copyright' => 'nullable|string|max:500',
            'metadata_copyright_source' => 'nullable|string|max:500',
        ];
    }
}
