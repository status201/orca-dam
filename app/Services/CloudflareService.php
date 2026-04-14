<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareService
{
    protected bool $enabled;

    protected string $apiToken;

    protected string $zoneId;

    public function __construct()
    {
        $this->enabled = (bool) config('cloudflare.enabled', false);
        $this->apiToken = (string) config('cloudflare.api_token', '');
        $this->zoneId = (string) config('cloudflare.zone_id', '');
    }

    /**
     * Check if Cloudflare cache purging is enabled and configured.
     *
     * Requires: CLOUDFLARE_ENABLED=true in .env, API credentials set,
     * custom_domain configured, and cloudflare_cache_purge setting enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled
            && $this->apiToken !== ''
            && $this->zoneId !== ''
            && Setting::get('custom_domain', '') !== ''
            && (bool) Setting::get('cloudflare_cache_purge', false);
    }

    /**
     * Collect all public URLs for an asset (original + thumbnail + resize variants).
     *
     * Call this BEFORE the asset's S3 keys are reset to null during replacement,
     * so that the thumbnail/resize URLs are still available on the model.
     *
     * @return array<string>
     */
    public function collectAssetUrls(Asset $asset): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $baseUrl = S3Service::getPublicBaseUrl();
        $urls = [$baseUrl.'/'.$asset->s3_key];

        if ($asset->thumbnail_s3_key) {
            $urls[] = $baseUrl.'/'.$asset->thumbnail_s3_key;
        }
        if ($asset->resize_s_s3_key) {
            $urls[] = $baseUrl.'/'.$asset->resize_s_s3_key;
        }
        if ($asset->resize_m_s3_key) {
            $urls[] = $baseUrl.'/'.$asset->resize_m_s3_key;
        }
        if ($asset->resize_l_s3_key) {
            $urls[] = $baseUrl.'/'.$asset->resize_l_s3_key;
        }

        return $urls;
    }

    /**
     * Purge one or more URLs from Cloudflare's cache.
     *
     * Accepts up to 30 URLs per call (Cloudflare API limit).
     * Returns true on success, false on failure. Failures are logged
     * but never thrown -- callers should not depend on purge success.
     *
     * @param  array<string>  $urls
     */
    public function purgeUrls(array $urls): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $urls = array_values(array_filter($urls));

        if (empty($urls)) {
            return true;
        }

        try {
            $response = Http::withToken($this->apiToken)
                ->timeout(10)
                ->post(
                    "https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/purge_cache",
                    ['files' => $urls]
                );

            if ($response->successful() && $response->json('success')) {
                Log::info('Cloudflare cache purged for '.count($urls).' URL(s).');

                return true;
            }

            Log::error('Cloudflare cache purge failed: '.$response->body());

            return false;
        } catch (\Exception $e) {
            Log::error('Cloudflare cache purge failed: '.$e->getMessage());

            return false;
        }
    }
}
