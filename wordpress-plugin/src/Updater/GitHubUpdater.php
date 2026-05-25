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
        // Strip the `wp-v` prefix so WP sees a clean semver. Also attach the
        // ORCA logo so WP renders our brand on the plugin row instead of the
        // generic plug-shaped placeholder.
        add_filter('puc_request_info_result-orca-dam-picker', static function ($info) {
            if (! is_object($info)) {
                return $info;
            }
            if (isset($info->version) && is_string($info->version)) {
                $info->version = preg_replace('/^wp-v/', '', $info->version);
            }
            $info->icons = [
                'svg'     => plugins_url('assets/orca-logo.svg', ORCA_DAM_PICKER_FILE),
                '1x'      => plugins_url('assets/icon-256x256.png', ORCA_DAM_PICKER_FILE),
                '2x'      => plugins_url('assets/icon-256x256.png', ORCA_DAM_PICKER_FILE),
                'default' => plugins_url('assets/icon-256x256.png', ORCA_DAM_PICKER_FILE),
            ];
            // Inject rich content for the "View details" modal. Without this
            // WP shows only the plugin-header one-liner — see image of the
            // pre-0.4.2 modal for how bare that looked.
            $info->sections = PluginDetailsContent::sections();
            return $info;
        });
    }
}
