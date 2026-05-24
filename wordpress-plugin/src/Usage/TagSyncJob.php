<?php

declare(strict_types=1);

namespace OrcaDam\Usage;

use OrcaDam\Api\OrcaClient;

/**
 * Action Scheduler / WP-Cron handler that calls ORCA's reference-tag API.
 * Runs out of band so the editor save round-trip isn't blocked by network.
 */
final class TagSyncJob
{
    public const HOOK = 'orca_dam_tag_sync';

    public function __construct(private readonly OrcaClient $client) {}

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'handle'], 10, 3);
    }

    /**
     * @param list<int> $added   ORCA asset ids that became referenced
     * @param list<int> $removed ORCA asset ids that are no longer referenced
     */
    public function handle(string $tag, array $added, array $removed): void
    {
        foreach ($added as $assetId) {
            $response = $this->client->addReferenceTags((int) $assetId, [$tag]);
            if (! $response->ok()) {
                error_log(sprintf(
                    '[orca-dam-picker] addReferenceTags asset=%d tag=%s status=%d body=%s',
                    $assetId,
                    $tag,
                    $response->status,
                    substr($response->rawBody, 0, 200),
                ));
            }
        }

        foreach ($removed as $assetId) {
            $response = $this->client->removeReferenceTagByName((int) $assetId, $tag);
            if (! $response->ok() && $response->status !== 404) {
                error_log(sprintf(
                    '[orca-dam-picker] removeReferenceTag asset=%d tag=%s status=%d body=%s',
                    $assetId,
                    $tag,
                    $response->status,
                    substr($response->rawBody, 0, 200),
                ));
            }
        }

        update_option('orca_dam_last_sync', [
            'time'    => time(),
            'tag'     => $tag,
            'added'   => $added,
            'removed' => $removed,
        ], false);
    }
}
