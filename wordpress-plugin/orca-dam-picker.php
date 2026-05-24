<?php
/**
 * Plugin Name:       ORCA DAM Picker
 * Plugin URI:        https://github.com/status201/orca-dam
 * Description:       Browse and insert assets from ORCA DAM directly inside WordPress. Tracks usage automatically via ORCA reference tags.
 * Version:           0.3.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Studyflow
 * License:           MIT
 * Text Domain:       orca-dam-picker
 * Domain Path:       /languages
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('ORCA_DAM_PICKER_FILE', __FILE__);
define('ORCA_DAM_PICKER_DIR', plugin_dir_path(__FILE__));
define('ORCA_DAM_PICKER_URL', plugin_dir_url(__FILE__));
define('ORCA_DAM_PICKER_VERSION', '0.3.0');

$autoload = ORCA_DAM_PICKER_DIR . 'vendor/autoload.php';
if (! file_exists($autoload)) {
    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p><strong>ORCA DAM Picker:</strong> Composer dependencies are missing. Run <code>composer install</code> inside the plugin directory.</p></div>';
    });
    return;
}
require $autoload;

add_action('plugins_loaded', static function (): void {
    \OrcaDam\Plugin::instance()->boot();
});

register_activation_hook(__FILE__, [\OrcaDam\Plugin::class, 'onActivate']);
register_deactivation_hook(__FILE__, [\OrcaDam\Plugin::class, 'onDeactivate']);
