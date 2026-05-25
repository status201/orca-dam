<?php

declare(strict_types=1);

namespace OrcaDam\Editors;

use OrcaDam\Settings\CredentialStore;

/**
 * Enqueues the JS bundle that extends wp.media to add an "ORCA" tab.
 * The tab is implemented in assets/src/editors/gutenberg.js, but since all four
 * editor surfaces share wp.media, this one bundle services Gutenberg AND the
 * featured-image modal AND core block-editor MediaUpload.
 */
final class Gutenberg
{
    public function register(): void
    {
        add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockEditor']);
        add_action('wp_enqueue_media', [$this, 'enqueueMediaModal']);
    }

    public function enqueueBlockEditor(): void
    {
        $this->enqueueBundle('gutenberg');
    }

    public function enqueueMediaModal(): void
    {
        // Fires for both classic and block-editor screens when wp.media is loaded
        // (e.g. featured-image picker). Avoid double-enqueue inside the block editor.
        if (did_action('enqueue_block_editor_assets')) {
            return;
        }
        $this->enqueueBundle('gutenberg');
    }

    private function enqueueBundle(string $entry): void
    {
        $handle = 'orca-dam-' . $entry;
        $assetPath = ORCA_DAM_PICKER_DIR . "assets/build/editors/{$entry}.asset.php";

        $asset = file_exists($assetPath)
            ? require $assetPath
            : ['dependencies' => ['wp-element', 'wp-i18n', 'wp-api-fetch', 'wp-components', 'media-views'], 'version' => ORCA_DAM_PICKER_VERSION];

        wp_enqueue_script(
            $handle,
            ORCA_DAM_PICKER_URL . "assets/build/editors/{$entry}.js",
            $asset['dependencies'],
            $asset['version'],
            true,
        );
        wp_set_script_translations($handle, 'orca-dam-picker');

        wp_localize_script($handle, 'orcaDam', [
            'restUrl'     => esc_url_raw(rest_url('orca/v1')),
            'nonce'       => wp_create_nonce('wp_rest'),
            'orcaBaseUrl' => esc_url_raw((string) get_option(CredentialStore::OPTION_BASE_URL, '')),
        ]);
    }
}
