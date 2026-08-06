<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasUploadMetadataRules;
use App\Rules\AllowedUploadExtension;
use App\Rules\BoundedFilename;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssetRequest extends FormRequest
{
    use HasUploadMetadataRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            // `max:512000` is kilobytes — it bounds the bytes, not the name. BoundedFilename is
            // what bounds the name, which S3Service writes verbatim into assets.filename.
            'files.*' => ['required', 'file', 'max:512000', new AllowedUploadExtension, new BoundedFilename], // 500MB max
            // 100, matching FolderController's creation cap. folder + filename both feed s3_key,
            // now varchar(1024) — wide enough for the derived keys too (thumbnails/L/...), which
            // are longer than the key they come from. The cap stays because it keeps the folder
            // LIKE range inside assets_folder_filter_index's 255-character prefix.
            'folder' => 'nullable|string|max:100',
            'keep_original_filename' => 'nullable|boolean',
        ], $this->uploadMetadataRules());
    }
}
