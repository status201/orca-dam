<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/wp-stubs.php';

if (! defined('ORCA_DAM_PICKER_FILE')) {
    define('ORCA_DAM_PICKER_FILE', dirname(__DIR__) . '/orca-dam-picker.php');
    define('ORCA_DAM_PICKER_DIR', dirname(__DIR__) . '/');
    define('ORCA_DAM_PICKER_URL', 'https://site.test/wp-content/plugins/orca-dam-picker/');
}

if (! defined('ORCA_DAM_PICKER_VERSION')) {
    define('ORCA_DAM_PICKER_VERSION', '0.0.0-test');
}
if (! defined('ORCA_ENCRYPTION_KEY')) {
    define('ORCA_ENCRYPTION_KEY', 'test-key-for-unit-tests-32-bytes-padding');
}
