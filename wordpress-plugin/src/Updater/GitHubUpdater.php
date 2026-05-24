<?php

declare(strict_types=1);

namespace OrcaDam\Updater;

/**
 * Bootstraps Yahnis Elsts' Plugin Update Checker against the ORCA repo's
 * GitHub Releases feed (filtered to `wp-v*` tags so only plugin releases are
 * considered, not the Laravel app's own tags).
 */
final class GitHubUpdater
{
    public function register(): void
    {
        if (! class_exists('YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
            return;
        }

        $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/status201/orca-dam/',
            ORCA_DAM_PICKER_FILE,
            'orca-dam-picker',
        );

        // Restrict to plugin-tagged releases.
        $checker->getVcsApi()?->enableReleaseAssets('/orca-dam-picker.*\.zip/i');
        if (method_exists($checker, 'setBranch')) {
            $checker->setBranch('main');
        }

        add_filter('puc_request_info_result-orca-dam-picker', static function ($info) {
            if (is_object($info) && isset($info->version) && ! str_starts_with((string) $info->version, '0.') && ! str_contains((string) $info->version, 'wp-')) {
                // Ignore tags that don't look like wp-v*
                return null;
            }
            return $info;
        });
    }
}
