<?php

declare(strict_types=1);

namespace OrcaDam\Tests\Unit;

use Brain\Monkey\Functions;
use OrcaDam\Tests\Support\WpTestCase;
use OrcaDam\Updater\GitHubUpdater;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * The repo also carries the Laravel app's `v*` tags. When the `wp-v*` release
 * lookup failed, PUC fell back to the highest tag and offered app `v1.8.0` as a
 * plugin update to 0.4.8 sites. Only the release strategy may survive.
 */
final class GitHubUpdaterTest extends WpTestCase
{
    public function test_only_the_release_strategy_survives_against_puc_real_strategies(): void
    {
        Functions\when('wp_parse_url')->alias('parse_url');

        // composer.lock is not committed, so resolve whichever PUC minor
        // (v5p6, v5p7, …) is installed rather than naming one.
        $class = PucFactory::getLatestClassVersion('GitHubApi');
        $api = new $class('https://github.com/status201/orca-dam/');
        $strategies = (new \ReflectionMethod($api, 'getUpdateDetectionStrategies'))->invoke($api, 'main');

        $this->assertArrayHasKey($api::STRATEGY_LATEST_TAG, $strategies, 'PUC no longer has a tag fallback; revisit releasesOnly()');
        $this->assertSame([$api::STRATEGY_LATEST_RELEASE], array_keys(GitHubUpdater::releasesOnly($strategies)));
    }
}
