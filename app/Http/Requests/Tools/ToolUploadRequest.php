<?php

namespace App\Http\Requests\Tools;

use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request for the /tools upload endpoints. Holds the shared rules + the
 * "can create assets" gate; subclasses supply per-tool rules via extraRules().
 */
abstract class ToolUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Asset::class) ?? false;
    }

    public function rules(): array
    {
        return array_merge([
            'filename' => ['required', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:1000'],
        ], $this->extraRules());
    }

    /**
     * Per-tool rules: the `content` field (with its size cap) plus any extra
     * fields (width/height/parent_asset_id/latex/metadata).
     */
    abstract protected function extraRules(): array;
}
