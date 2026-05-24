<?php

declare(strict_types=1);

namespace OrcaDam\Editors;

/**
 * Adds an "Insert from ORCA" button to the classic editor toolbar. The actual
 * click handler also reuses wp.media, so the same gutenberg.js bundle handles
 * everything; this class only adds the toolbar button.
 */
final class ClassicEditor
{
    public function register(): void
    {
        add_action('media_buttons', [$this, 'renderButton'], 15);
    }

    public function renderButton(): void
    {
        if (! current_user_can('upload_files')) {
            return;
        }
        printf(
            '<button type="button" class="button orca-dam-insert" data-orca-classic="1"><span class="dashicons dashicons-cloud" style="vertical-align:text-bottom"></span> %s</button>',
            esc_html__('Insert from ORCA', 'orca-dam-picker'),
        );
    }
}
