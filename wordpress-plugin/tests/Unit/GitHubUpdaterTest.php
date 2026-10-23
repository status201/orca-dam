<?php

declare(strict_types=1);

namespace OrcaDam\Tests\Unit;

use Brain\Monkey\Filters;
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
    private const NOTES = "<ul>\n<li>Fixed the thing.</li>\n</ul>";
    private const INSTALLED = "<h4>0.4.9</h4>\n<ul><li>Older entry.</li></ul>";

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
    }

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

    public function test_a_newer_release_puts_its_notes_above_the_installed_changelog(): void
    {
        $this->assertSame(
            "<h4>0.4.10</h4>\n" . self::NOTES . "\n\n" . self::INSTALLED,
            GitHubUpdater::changelog('0.4.9', '0.4.10', self::NOTES, self::INSTALLED),
        );
    }

    public function test_the_installed_changelog_stands_alone_when_nothing_newer_has_notes(): void
    {
        // Same version: the installed changelog already has the entry.
        $this->assertSame(self::INSTALLED, GitHubUpdater::changelog('0.4.9', '0.4.9', self::NOTES, self::INSTALLED));
        // Empty release body, or PUC's placeholder for one.
        $this->assertSame(self::INSTALLED, GitHubUpdater::changelog('0.4.9', '0.4.10', " \n", self::INSTALLED));
        $this->assertSame(self::INSTALLED, GitHubUpdater::changelog('0.4.9', '0.4.10', 'There is no changelog available.', self::INSTALLED));
    }

    /**
     * Before 0.4.10 the filter replaced every section with the installed copy's,
     * so "View version 0.4.9 details" on a 0.4.8 site listed only up to 0.4.8.
     */
    public function test_the_details_window_shows_the_offered_release_notes_on_an_older_install(): void
    {
        Functions\when('wp_parse_url')->alias('parse_url');
        Functions\when('plugin_basename')->justReturn('orca-dam-picker/orca-dam-picker.php');
        Functions\when('wp_next_scheduled')->justReturn(1_900_000_000);
        Functions\when('register_deactivation_hook')->justReturn(null);
        Functions\when('plugins_url')->returnArg(1);

        $filter = null;
        Filters\expectAdded('puc_request_info_result-orca-dam-picker')->once()->whenHappen(function (callable $callback) use (&$filter): void {
            $filter = $callback;
        });
        (new GitHubUpdater())->register();

        // The shape PUC hands over: tag-derived version, release body as the changelog.
        $offered = $this->nextVersion(ORCA_DAM_PICKER_VERSION);
        $info = $filter((object) ['version' => "wp-v{$offered}", 'sections' => ['changelog' => self::NOTES]]);

        $this->assertSame($offered, $info->version);
        $this->assertStringStartsWith("<h4>{$offered}</h4>\n" . self::NOTES, $info->sections['changelog']);
        $this->assertStringContainsString('<h4>0.4.8</h4>', $info->sections['changelog'], 'the installed history is gone');
        $this->assertArrayHasKey('description', $info->sections);
        $this->assertArrayHasKey('installation', $info->sections);
    }

    private function nextVersion(string $version): string
    {
        [$major] = explode('.', $version);
        return ((int) $major + 1) . '.0.0';
    }
}
