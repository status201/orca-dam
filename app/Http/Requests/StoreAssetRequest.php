<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
