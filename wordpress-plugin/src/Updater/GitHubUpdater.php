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

        $vcsApi = $checker->getVcsApi();
        if ($vcsApi !== null) {
            $vcsApi->enableReleaseAssets('/orca-dam-picker.*\.zip/i');
            // Only consider tags shaped like `wp-vX.Y.Z` so the updater never
            // pulls a Laravel-app release tag from the same repo.
            $vcsApi->setReleaseVersionFilter('/^wp-v\d+\.\d+\.\d+/');
        }

        if (method_exists($checker, 'setBranch')) {
            $checker->setBranch('main');
        }

        // PUC's ltrim($tag, 'v') only strips a leading "v", so a `wp-v0.3.0`
        // tag stays "wp-v0.3.0" — WP's version_compare then treats the
        // non-numeric prefix as garbage and "Check for updates" reports
        // up-to-date even when the file header is still on the older version.
        // Strip the `wp-v` prefix so WP sees a clean semver.
        add_filter('puc_request_info_result-orca-dam-picker', static function ($info) {
            if (is_object($info) && isset($info->version) && is_string($info->version)) {
                $info->version = preg_replace('/^wp-v/', '', $info->version);
            }
            return $info;
        });
    }
}
