<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (! defined('ORCA_DAM_PICKER_VERSION')) {
    define('ORCA_DAM_PICKER_VERSION', '0.0.0-test');
}
if (! defined('ORCA_ENCRYPTION_KEY')) {
    define('ORCA_ENCRYPTION_KEY', 'test-key-for-unit-tests-32-bytes-padding');
}
