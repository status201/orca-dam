<?php

namespace App\Support;

/**
 * Validation rules for the `asset_ids` list every bulk action takes.
 */
final class BulkAssetIds
{
    /**
     * @param  bool  $mustExist  false for trash actions, whose ids are soft-deleted and filtered by onlyTrashed()
     * @return array<string, string>
     */
    public static function rules(int $max = 500, bool $mustExist = true): array
    {
        return [
            'asset_ids' => 'required|array|max:'.$max,
            'asset_ids.*' => $mustExist ? 'integer|exists:assets,id' : 'integer',
        ];
    }
}
