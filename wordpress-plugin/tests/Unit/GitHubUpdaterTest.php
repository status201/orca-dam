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
        $api = $this->gitHubApi();
        $strategies = (new \ReflectionMethod($api, 'getUpdateDetectionStrategies'))->invoke($api, 'main');

        $this->assertArrayHasKey($api::STRATEGY_LATEST_TAG, $strategies, 'PUC no longer has a tag fallback; revisit releasesOnly()');
        $this->assertSame([$api::STRATEGY_LATEST_RELEASE], array_keys(GitHubUpdater::releasesOnly($strategies)));
    }

    /**
     * PUC looks for the plugin file and readme.txt at the repo root, where they
     * never are. Driven through PUC's own lookup so a change to the URLs it
     * builds fails here instead of silently reaching GitHub again.
     */
    public function test_puc_repo_root_lookups_get_a_local_404_and_nothing_else_does(): void
    {
        $answeredLocally = [];
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\when('add_query_arg')->alias(fn (array $args, string $url): string => $url . '?' . http_build_query($args));
        Functions\when('is_wp_error')->alias(fn ($thing): bool => $thing instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn (array $response): int => $response['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn (array $response): string => $response['body']);
        Functions\when('wp_remote_get')->alias(function (string $url) use (&$answeredLocally): array {
            $local = GitHubUpdater::skipRepoRootLookups(false, [], $url);
            $answeredLocally[] = $local !== false;
            return $local !== false ? $local : ['response' => ['code' => 500], 'body' => ''];
        });

        $api = $this->gitHubApi();
        $this->assertNull($api->getRemoteFile('orca-dam-picker.php', 'wp-v0.4.9'));
        $this->assertNull($api->getRemoteFile('readme.txt', 'wp-v0.4.9'));
        $api->getLatestRelease();
        $api->getRemoteFile('wordpress-plugin/readme.txt', 'wp-v0.4.9');

        $this->assertSame([true, true, false, false], $answeredLocally);
    }

    public function test_the_release_lookup_skips_app_releases_and_takes_the_plugin_zip(): void
    {
        $api = $this->releaseLookupAgainst([
            $this->release('v1.8.0'),
            $this->release('wp-v0.5.0', 'orca-dam-picker-0.5.0.zip'),
        ]);

        $reference = $api->getLatestRelease();

        $this->assertSame('wp-v0.5.0', $reference->version);
        $this->assertSame('https://github.com/dl/orca-dam-picker-0.5.0.zip', $reference->downloadUrl);
    }

    /**
     * Without the built zip, PUC would offer the release's source zipball: the
     * whole orca-dam repo installed as the plugin. No update beats that.
     */
    public function test_a_plugin_release_without_its_zip_offers_no_update(): void
    {
        $api = $this->releaseLookupAgainst([$this->release('wp-v0.5.0')]);

        $this->assertNull($api->getLatestRelease());
    }

    public function test_the_404_shortcut_leaves_other_requests_alone(): void
    {
        $preempted = ['response' => ['code' => 200]];
        $url = 'https://api.github.com/repos/status201/orca-dam/contents/readme.txt?ref=wp-v0.4.9';

        $this->assertSame($preempted, GitHubUpdater::skipRepoRootLookups($preempted, [], $url));
        $this->assertFalse(GitHubUpdater::skipRepoRootLookups(false, [], 'https://api.github.com/repos/other/repo/contents/readme.txt'));
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
     * Before 0.4.9 the filter replaced every section with the installed copy's,
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

    /**
     * A PUC GitHubApi configured like the plugin's, answering `/releases` with $releases.
     *
     * @param list<array<string, mixed>> $releases
     */
    private function releaseLookupAgainst(array $releases): object
    {
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\when('add_query_arg')->alias(fn (array $args, string $url): string => $url . '?' . http_build_query($args));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->returnArg(1);
        Functions\when('wp_remote_get')->alias(fn (): string => json_encode($releases));

        $api = $this->gitHubApi();
        GitHubUpdater::configureReleaseLookup($api);
        return $api;
    }

    /** @return array<string, mixed> */
    private function release(string $tag, ?string $zip = null): array
    {
        return [
            'tag_name'    => $tag,
            'draft'       => false,
            'prerelease'  => false,
            'created_at'  => '2026-10-23T09:26:35Z',
            'zipball_url' => "https://api.github.com/repos/status201/orca-dam/zipball/{$tag}",
            'body'        => '',
            'assets'      => $zip === null ? [] : [[
                'name'                 => $zip,
                'url'                  => 'https://api.github.com/repos/status201/orca-dam/releases/assets/1',
                'browser_download_url' => "https://github.com/dl/{$zip}",
                'download_count'       => 0,
            ]],
        ];
    }

    private function gitHubApi(): object
    {
        Functions\when('wp_parse_url')->alias('parse_url');

        // composer.lock is not committed, so resolve whichever PUC minor
        // (v5p6, v5p7, …) is installed rather than naming one.
        $class = PucFactory::getLatestClassVersion('GitHubApi');
        return new $class('https://github.com/status201/orca-dam/');
    }

    private function nextVersion(string $version): string
    {
        [$major] = explode('.', $version);
        return ((int) $major + 1) . '.0.0';
    }
}
