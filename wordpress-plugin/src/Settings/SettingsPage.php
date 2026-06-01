<?php

declare(strict_types=1);

namespace OrcaDam\Settings;

/**
 * Renders the Settings → ORCA DAM page. UI is a React app; this class only
 * mounts the root element, prints the JS data island, and handles the form POST.
 */
final class SettingsPage
{
    public const PAGE_SLUG = 'orca-dam-picker';
    public const NONCE_ACTION = 'orca_dam_save_settings';

    public function __construct(private readonly CredentialStore $credentials) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_orca_dam_save_settings', [$this, 'handleSave']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function addMenu(): void
    {
        add_options_page(
            __('ORCA DAM', 'orca-dam-picker'),
            __('ORCA DAM', 'orca-dam-picker'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render'],
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'orca-dam-picker'));
        }
        echo '<div class="wrap"><div id="orca-dam-settings-root"></div></div>';
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== 'settings_page_' . self::PAGE_SLUG) {
            return;
        }

        $handle = 'orca-dam-settings';
        $asset = $this->loadAssetMeta('settings');

        wp_enqueue_script(
            $handle,
            ORCA_DAM_PICKER_URL . 'assets/build/settings.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );
        wp_set_script_translations($handle, 'orca-dam-picker', ORCA_DAM_PICKER_DIR . 'languages');

        wp_localize_script($handle, 'orcaDamSettings', [
            'restUrl'        => esc_url_raw(rest_url('orca/v1')),
            'nonce'          => wp_create_nonce('wp_rest'),
            'saveUrl'        => esc_url_raw(admin_url('admin-post.php')),
            'saveNonce'      => wp_create_nonce(self::NONCE_ACTION),
            'baseUrl'        => $this->credentials->baseUrl(),
            'hasToken'       => $this->credentials->token() !== null,
            'defaultFolder'  => $this->credentials->defaultFolder(),
            'siteHost'       => wp_parse_url(home_url(), PHP_URL_HOST) ?: '',
        ]);
    }

    public function handleSave(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'orca-dam-picker'));
        }
        check_admin_referer(self::NONCE_ACTION);

        $baseUrl = isset($_POST['base_url']) ? wp_unslash((string) $_POST['base_url']) : '';
        $token = isset($_POST['token']) ? wp_unslash((string) $_POST['token']) : '';
        $folder = isset($_POST['default_folder']) ? wp_unslash((string) $_POST['default_folder']) : '';

        $this->credentials->setBaseUrl($baseUrl);
        $this->credentials->setDefaultFolder($folder);
        if ($token !== '') {
            $this->credentials->setToken($token);
        }

        wp_safe_redirect(add_query_arg('orca-saved', '1', admin_url('options-general.php?page=' . self::PAGE_SLUG)));
        exit;
    }

    /**
     * @return array{dependencies: array<int, string>, version: string}
     */
    private function loadAssetMeta(string $entry): array
    {
        $path = ORCA_DAM_PICKER_DIR . "assets/build/{$entry}.asset.php";
        if (file_exists($path)) {
            /** @var array{dependencies: array<int, string>, version: string} $asset */
            $asset = require $path;
            return $asset;
        }
        return ['dependencies' => ['wp-element', 'wp-api-fetch', 'wp-components', 'wp-i18n'], 'version' => ORCA_DAM_PICKER_VERSION];
    }
}
