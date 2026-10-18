<?php

namespace App\Http\Requests\Tools;

use App\Models\Asset;
use App\Support\ColumnLimits;
use App\Support\UploadMetadata;
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
            'filename' => ['required', 'string', 'max:'.ColumnLimits::for('assets', 'filename')],
            // 100, matching FolderController's creation cap — see StoreAssetRequest.
            'folder' => ['nullable', 'string', 'max:100'],
            'caption' => ['nullable', 'string', 'max:1000'],
        ], $this->extraRules());
    }

    /**
     * Per-tool rules: the `content` field (with its size cap) plus any extra
     * fields (width/height/parent_asset_id/latex/metadata).
     */
    abstract protected function extraRules(): array;

    /**
     * Batch upload metadata for the asset. None by default; a tool whose request uses
     * HasUploadMetadataRules gets that trait's accessor instead.
     */
    public function uploadMetadata(): ?UploadMetadata
    {
        return null;
    }
}
