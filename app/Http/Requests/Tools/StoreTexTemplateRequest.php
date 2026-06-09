<?php

namespace App\Http\Requests\Tools;

class StoreTexTemplateRequest extends ToolUploadRequest
{
    protected function extraRules(): array
    {
        return [
            'content' => ['required', 'string', 'max:1048576'],
        ];
    }
}
