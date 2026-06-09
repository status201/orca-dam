<?php

namespace App\Http\Requests\Tools;

use App\Http\Requests\Concerns\HasUploadMetadataRules;

class StoreTikzSvgRequest extends ToolUploadRequest
{
    use HasUploadMetadataRules;

    protected function extraRules(): array
    {
        return array_merge([
            'content' => ['required', 'string', 'max:5242880'],
            'parent_asset_id' => ['nullable', 'integer', 'exists:assets,id'],
        ], $this->uploadMetadataRules());
    }
}
