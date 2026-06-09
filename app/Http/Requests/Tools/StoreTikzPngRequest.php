<?php

namespace App\Http\Requests\Tools;

use App\Http\Requests\Concerns\HasUploadMetadataRules;

class StoreTikzPngRequest extends ToolUploadRequest
{
    use HasUploadMetadataRules;

    protected function extraRules(): array
    {
        return array_merge([
            'content' => ['required', 'string', 'max:10485760'],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
            'parent_asset_id' => ['nullable', 'integer', 'exists:assets,id'],
        ], $this->uploadMetadataRules());
    }
}
