<?php

namespace App\Support;

use App\Models\Tag;

/**
 * Central helper for turning free-text tag input into a clean list of tag names.
 *
 * Accepts a single string or an array of strings, where any element may itself be a
 * comma-separated list (e.g. "cat, dog, animal"). Splits on commas, trims, lowercases,
 * drops empty and over-length names, and de-duplicates while preserving first-seen order.
 *
 * This is the single source of truth for the comma-splitting behavior shared by CSV
 * import (ImportController) and the asset tag-attach endpoints, so a client that sends
 * "a,b,c" as one value behaves identically everywhere. Tag id/type resolution stays in
 * Tag::resolveUserTagIds() / resolveReferenceTagIds() — this class only shapes names.
 */
class TagInputParser
{
    /**
     * @param  string|array<int, string|null>|null  $input
     * @return array<int, string>
     */
    public static function parse(string|array|null $input, int $maxLength = Tag::MAX_NAME_LENGTH): array
    {
        $pieces = is_array($input) ? $input : [$input];
        $names = [];

        foreach ($pieces as $piece) {
            foreach (explode(',', (string) $piece) as $name) {
                $name = mb_strtolower(trim($name));

                if ($name !== '' && mb_strlen($name) <= $maxLength) {
                    $names[$name] = true; // keyed dedup preserves first-seen order
                }
            }
        }

        return array_keys($names);
    }
}
