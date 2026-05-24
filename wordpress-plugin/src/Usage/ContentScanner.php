<?php

declare(strict_types=1);

namespace OrcaDam\Usage;

use OrcaDam\Attachments\ShellFactory;

/**
 * Extracts the set of ORCA asset IDs referenced by a piece of post content.
 *
 * Two passes:
 *  1. parse_blocks() walk for core/image, core/gallery, core/cover — picks up the
 *     standard `id` attribute and resolves it through WP's attachment meta.
 *  2. DOMDocument pass over the raw rendered HTML to catch arbitrary <img> tags
 *     carrying `data-orca-asset-id` or matching a known ORCA URL.
 */
final class ContentScanner
{
    /**
     * @return list<int>  Distinct ORCA asset IDs (not WP attachment IDs)
     */
    public function extract(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $assetIds = [];

        $blocks = function_exists('parse_blocks') ? parse_blocks($content) : [];
        $this->walkBlocks($blocks, $assetIds);

        $this->walkDom($content, $assetIds);

        return array_values(array_unique(array_filter($assetIds, static fn ($id) => $id > 0)));
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param list<int> $assetIds
     */
    private function walkBlocks(array $blocks, array &$assetIds): void
    {
        foreach ($blocks as $block) {
            $name = (string) ($block['blockName'] ?? '');
            $attrs = $block['attrs'] ?? [];

            if (in_array($name, ['core/image', 'core/cover', 'core/media-text'], true)) {
                $this->collectFromAttachment((int) ($attrs['id'] ?? 0), $assetIds);
            }
            if ($name === 'core/gallery') {
                $ids = $attrs['ids'] ?? [];
                if (is_array($ids)) {
                    foreach ($ids as $id) {
                        $this->collectFromAttachment((int) $id, $assetIds);
                    }
                }
            }

            if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $this->walkBlocks($block['innerBlocks'], $assetIds);
            }
        }
    }

    /**
     * @param list<int> $assetIds
     */
    private function collectFromAttachment(int $attachmentId, array &$assetIds): void
    {
        if ($attachmentId === 0) {
            return;
        }
        $assetId = (int) get_post_meta($attachmentId, ShellFactory::META_ASSET_ID, true);
        if ($assetId > 0) {
            $assetIds[] = $assetId;
        }
    }

    /**
     * @param list<int> $assetIds
     */
    private function walkDom(string $content, array &$assetIds): void
    {
        if (! str_contains($content, '<')) {
            return;
        }

        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        foreach ($doc->getElementsByTagName('img') as $img) {
            /** @var \DOMElement $img */
            $explicit = (int) $img->getAttribute('data-orca-asset-id');
            if ($explicit > 0) {
                $assetIds[] = $explicit;
                continue;
            }
            $class = (string) $img->getAttribute('class');
            if (preg_match('/wp-image-(\d+)/', $class, $m)) {
                $this->collectFromAttachment((int) $m[1], $assetIds);
            }
        }
    }
}
