<?php

namespace App\Rules;

use App\Support\UploadPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validates that an uploaded file (or a filename string, for chunked uploads
 * where the file is not yet present) carries an allowlisted extension.
 */
class AllowedUploadExtension implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $name = $value instanceof UploadedFile
            ? $value->getClientOriginalName()
            : (is_string($value) ? $value : '');

        if ($name === '' || ! UploadPolicy::isAllowed($name)) {
            $fail(__('This file type is not allowed.'));
        }
    }
}
