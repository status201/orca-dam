<?php

namespace App\Support;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filters an asset list can apply, applied in one place.
 *
 * The web index/embed/cycle-nav query and the REST index/search endpoints each build one from
 * their own request parameters. Deciding *whether* a user may filter by `user` stays with the
 * caller (the web and REST rules differ); this applies whatever it is given.
 *
 * @see specs/features/asset-model.md
 */
final class AssetFilter
{
    /**
     * @param  array<int, int|string>  $tagIds
     * @param  list<int>|null  $ids  explicit ids, or null for no id filter
     */
    public function __construct(
        public readonly ?string $search = null,
        public readonly array $tagIds = [],
        public readonly ?string $type = null,
        public readonly ?string $folder = null,
        public readonly ?int $userId = null,
        public readonly bool $missingOnly = false,
        public readonly ?array $ids = null,
        public readonly ?string $sort = null,
    ) {}

    /**
     * The `tags` parameter as sent: an array, or a comma-separated string.
     *
     * @return array<int, int|string>
     */
    public static function tagIds(mixed $raw): array
    {
        if (! $raw) {
            return [];
        }

        return is_array($raw) ? $raw : explode(',', (string) $raw);
    }

    /**
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public function apply(Builder $query): Builder
    {
        if ($this->ids !== null) {
            $query->whereIn('id', $this->ids);
        }

        if ($this->search) {
            $query->search($this->search);
        }

        $query->withTags($this->tagIds)
            ->ofType($this->type)
            ->inFolder($this->folder)
            ->byUser($this->userId);

        if ($this->missingOnly) {
            $query->missing();
        }

        return AssetSort::fromInput($this->sort)->apply($query);
    }
}
