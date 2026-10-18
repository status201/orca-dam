<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * The asset list's sort orders: each key's ordering and its label, in one place. The model scope,
 * the cycle-nav summary and the grid's sort <select> used to keep three copies of this list.
 *
 * @see specs/features/asset-model.md
 */
enum AssetSort: string
{
    case DateDesc = 'date_desc';
    case DateAsc = 'date_asc';
    case UploadDesc = 'upload_desc';
    case UploadAsc = 'upload_asc';
    case SizeDesc = 'size_desc';
    case SizeAsc = 'size_asc';
    case NameAsc = 'name_asc';
    case NameDesc = 'name_desc';
    case S3KeyAsc = 's3key_asc';
    case S3KeyDesc = 's3key_desc';

    /**
     * The sort a request asked for. Unknown, null and empty all mean the default: `?sort=` arrives
     * as null (ConvertEmptyStringsToNull), and used to be a TypeError.
     */
    public static function fromInput(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::DateDesc;
    }

    /**
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function apply(Builder $query): Builder
    {
        return match ($this) {
            self::DateDesc => $query->latest('updated_at'),
            self::DateAsc => $query->oldest('updated_at'),
            self::UploadDesc => $query->latest('created_at'),
            self::UploadAsc => $query->oldest('created_at'),
            self::SizeDesc => $query->orderBy('size', 'desc'),
            self::SizeAsc => $query->orderBy('size', 'asc'),
            self::NameAsc => $query->orderBy('filename', 'asc'),
            self::NameDesc => $query->orderBy('filename', 'desc'),
            self::S3KeyAsc => $query->orderBy('s3_key', 'asc'),
            self::S3KeyDesc => $query->orderBy('s3_key', 'desc'),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DateDesc => __('Newest First'),
            self::DateAsc => __('Oldest First'),
            self::UploadDesc => __('Newest Uploads'),
            self::UploadAsc => __('Oldest Uploads'),
            self::SizeDesc => __('Largest First'),
            self::SizeAsc => __('Smallest First'),
            self::NameAsc => __('Name A-Z'),
            self::NameDesc => __('Name Z-A'),
            self::S3KeyAsc => __('S3 Key A-Z'),
            self::S3KeyDesc => __('S3 Key Z-A'),
        };
    }
}
