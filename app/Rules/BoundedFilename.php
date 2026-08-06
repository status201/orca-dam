<?php

namespace App\Rules;

use App\Support\ColumnLimits;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validates that an uploaded file's name fits the column it will be stored in.
 *
 * `S3Service::uploadFile()` returns `getClientOriginalName()` verbatim as the `filename` field, so
 * the browser's name lands in `assets.filename` unmodified — sanitisation applies to the S3 key,
 * not to this column. Nothing capped it: `StoreAssetRequest` validated `files.*` as a *file*
 * (`max:512000`, kilobytes) and never looked at the name, so a 300-character filename passed
 * validation and MariaDB rejected the insert as SQLSTATE 22001. The same class of bug as the
 * over-length copyright, on a path nobody had checked.
 *
 * Note this is independent of `keep_original_filename`. That flag decides whether the *S3 key*
 * reuses the name or gets a UUID; the `filename` column is written from the original either way,
 * so capping only the keep-filename path would leave the overflow exactly where it was.
 *
 * The limit is read from ColumnLimits rather than written here, so the rule, the column and the
 * edit form's `maxlength` cannot drift apart — see specs/features/input-validation.md REQ-1.
 * Mirrors AllowedUploadExtension in accepting either an UploadedFile or a plain string, because
 * the chunked path validates a filename before any file exists.
 *
 * @see specs/features/input-validation.md REQ-13
 */
class BoundedFilename implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $name = $value instanceof UploadedFile
            ? $value->getClientOriginalName()
            : (is_string($value) ? $value : '');

        $limit = ColumnLimits::for('assets', 'filename');

        // mb_strlen, not strlen: MySQL counts characters, and an accented or emoji filename would
        // otherwise be rejected for bytes the column does not charge it for.
        if (mb_strlen($name) > $limit) {
            $fail(__('The filename is too long (:length characters, maximum :max).', [
                'length' => mb_strlen($name),
                'max' => $limit,
            ]));
        }
    }
}
