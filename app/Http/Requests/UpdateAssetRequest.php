<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'filename' => 'sometimes|required|string|max:255',
            'alt_text' => 'nullable|string|max:500',
            'caption' => 'nullable|string|max:1000',
            'license_type' => 'nullable|string|max:255',
            'license_expiry_date' => 'nullable|date',
            'copyright' => 'nullable|string|max:500',
            'copyright_source' => 'nullable|string|max:500',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
        ];
    }
}
