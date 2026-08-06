<?php

namespace App\Http\Requests;

use App\Models\Tag;
use App\Support\ColumnLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Character caps come from ColumnLimits, never a literal — a rule that outruns its
     * column is how an over-length copyright became a 500. See
     * specs/features/input-validation.md.
     */
    public function rules(): array
    {
        return [
            'filename' => 'sometimes|required|string|max:'.ColumnLimits::for('assets', 'filename'),
            // alt_text/caption are TEXT columns; these caps are product decisions, well
            // inside the byte capacity (ColumnLimits::fitsText).
            'alt_text' => 'nullable|string|max:500',
            'caption' => 'nullable|string|max:1000',
            'license_type' => 'nullable|string|max:'.ColumnLimits::for('assets', 'license_type'),
            'license_expiry_date' => 'nullable|date',
            'copyright' => 'nullable|string|max:'.ColumnLimits::for('assets', 'copyright'),
            'copyright_source' => 'nullable|string|max:'.ColumnLimits::for('assets', 'copyright_source'),
            'tags' => 'nullable|array',
            // Tag::MAX_NAME_LENGTH, not a tighter literal: a tag creatable at 100 that this
            // rule then refuses to submit is a trap, not a cap.
            'tags.*' => 'string|max:'.Tag::MAX_NAME_LENGTH,
            'reference_tag_ids' => 'nullable|array',
            'reference_tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(fn ($q) => $q->where('type', 'reference')),
            ],
        ];
    }
}
