<?php

namespace App\Http\Requests\Tools;

class StoreMathmlRequest extends ToolUploadRequest
{
    protected function extraRules(): array
    {
        return [
            'content' => ['required', 'string', 'max:1000000'],
            'latex' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
