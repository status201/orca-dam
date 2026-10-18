<?php

namespace App\Http\Requests;

use App\Models\Tag;
use App\Support\ColumnLimits;
use App\Support\TagInputParser;
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
            'date_obtained' => 'nullable|date',
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

    /**
     * The editable metadata columns present in this request. s3_key is never among them
     * (ADR-006); filename is the editable display name.
     *
     * @return array<string, mixed>
     */
    public function metadataAttributes(): array
    {
        return $this->only([
            'filename', 'alt_text', 'caption', 'license_type',
            'license_expiry_date', 'date_obtained', 'copyright', 'copyright_source',
        ]);
    }

    /**
     * Whether the request means to change tags at all. Tags are only touched when sent.
     */
    public function touchesTags(): bool
    {
        return $this->has('tags') || $this->has('reference_tag_ids');
    }

    /**
     * The submitted user tags as ids (created on demand), or null to leave user tags alone.
     * Free text goes through TagInputParser like every other tag input (tag-input.md REQ-4).
     *
     * @return list<int>|null
     */
    public function userTagIds(): ?array
    {
        return $this->has('tags')
            ? Tag::resolveUserTagIds(TagInputParser::parse($this->input('tags', [])))
            : null;
    }

    /**
     * The submitted reference tag ids, or null to leave reference tags alone.
     *
     * @return list<int>|null
     */
    public function referenceTagIds(): ?array
    {
        return $this->has('reference_tag_ids')
            ? array_map('intval', (array) $this->input('reference_tag_ids', []))
            : null;
    }
}
