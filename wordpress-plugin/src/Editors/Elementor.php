<?php

declare(strict_types=1);

namespace OrcaDam\Editors;

/**
 * Elementor uses its own copy of wp.media for image controls; same bundle works
 * because Elementor delegates the modal to wp.media.view.MediaFrame.Select.
 * This class only enqueues the JS in Elementor's preview/editor frames.
 */
final class Elementor
{
    public function register(): void
    {
        add_action('elementor/editor/after_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        $handle = 'orca-dam-elementor';
        $assetPath = ORCA_DAM_PICKER_DIR . 'assets/build/editors/elementor.asset.php';

        $asset = file_exists($assetPath)
            ? require $assetPath
            : ['dependencies' => ['media-views', 'wp-i18n', 'wp-api-fetch', 'wp-element'], 'version' => ORCA_DAM_PICKER_VERSION];

        wp_enqueue_script(
            $handle,
            ORCA_DAM_PICKER_URL . 'assets/build/editors/elementor.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );

        wp_localize_script($handle, 'orcaDam', [
            'restUrl' => esc_url_raw(rest_url('orca/v1')),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }
}
