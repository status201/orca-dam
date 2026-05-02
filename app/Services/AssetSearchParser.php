<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Parses asset search input and applies the matching WHERE clauses to a query.
 *
 * Supports operator syntax:
 *   - bare `term`        — at least one regular term must match (OR)
 *   - `+term`            — required (AND)
 *   - `-term`            — excluded (NOT)
 *   - `"phrase"`         — required phrase
 *   - `+"phrase"`        — required phrase (explicit)
 *   - `-"phrase"`        — excluded phrase
 *
 * URLs that match the configured S3 bucket URL or custom domain are stripped
 * down to the bare s3_key before matching.
 */
class AssetSearchParser
{
    public static function apply(Builder $query, ?string $search): void
    {
        if (! $search) {
            return;
        }

        $normalized = self::normalizeSearchTerm($search);

        // If the input was a URL we recognized, treat it as a single literal term
        // (skip operator parsing — URLs contain `+` and `-` characters).
        if ($normalized !== $search) {
            $query->where(function ($q) use ($normalized) {
                self::addSearchCondition($q, $normalized);
            });

            return;
        }

        $terms = self::parseSearchTerms($normalized);

        // Regular terms: at least one must match (OR within a single where group)
        if (! empty($terms['regular'])) {
            $query->where(function ($q) use ($terms) {
                foreach ($terms['regular'] as $term) {
                    $q->orWhere(function ($sub) use ($term) {
                        self::addSearchCondition($sub, $term);
                    });
                }
            });
        }

        // Required terms: each must match in at least one field (AND)
        foreach ($terms['required'] as $term) {
            $query->where(function ($q) use ($term) {
                self::addSearchCondition($q, $term);
            });
        }

        // Excluded terms: must NOT match in any field
        foreach ($terms['excluded'] as $term) {
            $query->where(function ($q) use ($term) {
                self::addExcludeCondition($q, $term);
            });
        }
    }

    /**
     * Strip known URL prefixes from a search term so it matches s3_key.
     */
    public static function normalizeSearchTerm(string $search): string
    {
        $s3Url = rtrim(config('filesystems.disks.s3.url'), '/').'/';
        if (str_starts_with($search, $s3Url)) {
            return substr($search, strlen($s3Url));
        }

        $customDomain = Setting::get('custom_domain', '');
        if ($customDomain !== '' && $customDomain !== null) {
            $customUrl = rtrim($customDomain, '/').'/';
            if (str_starts_with($search, $customUrl)) {
                return substr($search, strlen($customUrl));
            }
        }

        return $search;
    }

    /**
     * Parse search string into regular, required (+), and excluded (-) terms.
     *
     * @return array{regular: string[], required: string[], excluded: string[]}
     */
    public static function parseSearchTerms(string $search): array
    {
        $terms = ['regular' => [], 'required' => [], 'excluded' => []];

        // Extract quoted phrases first: +"...", -"...", "..."
        $remaining = preg_replace_callback('/([+-])?"([^"]+)"/', function ($m) use (&$terms) {
            $phrase = trim($m[2]);
            if ($phrase === '') {
                return '';
            }

            if ($m[1] === '+') {
                $terms['required'][] = $phrase;
            } elseif ($m[1] === '-') {
                $terms['excluded'][] = $phrase;
            } else {
                $terms['required'][] = $phrase;
            }

            return '';
        }, $search);

        foreach (preg_split('/\s+/', $remaining, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            if (str_starts_with($token, '+') && strlen($token) > 1) {
                $terms['required'][] = substr($token, 1);
            } elseif (str_starts_with($token, '-') && strlen($token) > 1) {
                $terms['excluded'][] = substr($token, 1);
            } elseif ($token !== '+' && $token !== '-') {
                $terms['regular'][] = $token;
            }
        }

        return $terms;
    }

    private static function useFulltext(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb']);
    }

    /**
     * Wrap a term for FULLTEXT BOOLEAN MODE phrase matching to neutralize
     * its operator characters (+, -, *, ~, etc.).
     */
    private static function escapeFulltextTerm(string $term): string
    {
        return '"'.str_replace('"', '', $term).'"';
    }

    /**
     * Add OR search condition across all searchable fields for a single term.
     *
     * Uses FULLTEXT index on (alt_text, caption) for MySQL/MariaDB, falls back
     * to LIKE for SQLite (used in tests).
     */
    private static function addSearchCondition($query, string $term): void
    {
        $query->where('filename', 'like', "%{$term}%")
            ->orWhere('s3_key', 'like', "%{$term}%");

        if (self::useFulltext()) {
            $query->orWhereRaw(
                'MATCH(alt_text, caption) AGAINST(? IN BOOLEAN MODE)',
                [self::escapeFulltextTerm($term)]
            );
        } else {
            $query->orWhere('alt_text', 'like', "%{$term}%")
                ->orWhere('caption', 'like', "%{$term}%");
        }

        $query->orWhereHas('tags', function ($tagQuery) use ($term) {
            $tagQuery->where('name', 'like', "%{$term}%");
        });
    }

    /**
     * Add exclusion condition: asset must NOT match term in any field.
     */
    private static function addExcludeCondition($query, string $term): void
    {
        $query->where('filename', 'not like', "%{$term}%")
            ->where('s3_key', 'not like', "%{$term}%")
            ->where(function ($q) use ($term) {
                $q->whereNull('alt_text')->orWhere('alt_text', 'not like', "%{$term}%");
            })
            ->where(function ($q) use ($term) {
                $q->whereNull('caption')->orWhere('caption', 'not like', "%{$term}%");
            })
            ->whereDoesntHave('tags', function ($tagQuery) use ($term) {
                $tagQuery->where('name', 'like', "%{$term}%");
            });
    }
}
