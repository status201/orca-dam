<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Setting;

/**
 * The folder list (`s3_folders`) ORCA offers in its pickers — its only reader and writer.
 *
 * S3 has no folders, so the list is materialised into a setting. It used to be read, normalised
 * and written from S3Service, FolderController, DiscoverController and SystemController, each
 * with its own idea of what the list should contain.
 *
 * @see specs/features/folder-management.md
 */
class FolderRegistry
{
    private const SETTING = 's3_folders';

    /**
     * The folders a picker offers: the stored list, or the root folder alone. With a root folder
     * configured a stale '' is dropped and the root is always offered; a bucket-root install
     * always offers '' ("/ (root)") first.
     *
     * @return list<string>
     */
    public function forPicker(): array
    {
        $root = S3Service::getRootFolder();
        $folders = array_values((array) Setting::get(self::SETTING, []));

        if ($root === '') {
            if (! in_array('', $folders, true)) {
                array_unshift($folders, '');
            }

            return $folders;
        }

        $folders = array_values(array_filter($folders, fn ($folder) => $folder !== ''));
        if (! in_array($root, $folders, true)) {
            array_unshift($folders, $root);
        }

        return $folders;
    }

    /**
     * The stored list; when empty, derived once from the assets' own folders plus the root and
     * persisted, so a fresh install answers without an S3 round-trip (REQ-2).
     *
     * @return list<string>
     */
    public function cachedOrDerived(): array
    {
        $cached = (array) Setting::get(self::SETTING, []);
        if (! empty($cached)) {
            return $cached;
        }

        $root = S3Service::getRootFolder();
        $derived = Asset::select('s3_key')->get()
            ->map(fn (Asset $asset) => dirname($asset->s3_key))
            ->unique()
            ->values()
            ->all();

        $folders = array_values(array_unique(array_merge($root !== '' ? [$root] : [], $derived)));
        sort($folders);
        $this->replace($folders);

        return $folders;
    }

    /**
     * Replace the list wholesale — the authoritative refresh from a bucket scan (REQ-3).
     *
     * @param  list<string>  $folders
     */
    public function replace(array $folders): void
    {
        Setting::set(self::SETTING, $folders, 'json', 'aws');
    }

    /**
     * Add one folder (after it exists in the bucket — REQ-5), keeping the list sorted.
     */
    public function add(string $folder): void
    {
        $folders = (array) Setting::get(self::SETTING, []);
        if (in_array($folder, $folders, true)) {
            return;
        }

        $folders[] = $folder;
        sort($folders);
        $this->replace($folders);
    }

    /**
     * Drop the list, so it is rebuilt — when the root folder changes, every stored path is stale.
     */
    public function forget(): void
    {
        Setting::forget(self::SETTING);
    }
}
