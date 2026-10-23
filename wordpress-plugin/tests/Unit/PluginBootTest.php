<?php

declare(strict_types=1);

namespace OrcaDam\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use OrcaDam\Attachments\ImageDownsizeFilter;
use OrcaDam\Attachments\MetadataFilter;
use OrcaDam\Editors\Elementor;
use OrcaDam\Editors\Gutenberg;
use OrcaDam\Maintenance\CronScheduler;
use OrcaDam\Maintenance\OrphanScanner;
use OrcaDam\Plugin;
use OrcaDam\Settings\SettingsPage;
use OrcaDam\Tests\Support\WpTestCase;
use OrcaDam\Updater\GitHubUpdater;
use OrcaDam\Usage\PostObserver;
use OrcaDam\Usage\TagSyncJob;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Smoke test: boot the plugin against faked WordPress functions, the way
 * `plugins_loaded` does. A fatal error here would take down every site the
 * plugin is installed on, so this catches a broken class, a missing service
 * binding, or a dependency API change (the update checker) before release.
 */
final class PluginBootTest extends WpTestCase
{
    private Plugin $plugin;

    /** @var (callable(): void)|null */
    private $restInit = null;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('load_plugin_textdomain')->justReturn(true);
        Functions\when('plugin_basename')->justReturn('orca-dam-picker/orca-dam-picker.php');
        Functions\when('wp_parse_url')->alias('parse_url');
        // The update checker is built for real so a change to its API fails here.
        // It skips scheduling its own cron event when one already exists.
        Functions\when('wp_next_scheduled')->justReturn(1_900_000_000);
        Functions\when('register_deactivation_hook')->justReturn(null);
        Actions\expectAdded('rest_api_init')->once()->whenHappen(function (callable $callback): void {
            $this->restInit = $callback;
        });

        $this->plugin = new Plugin();
        $this->plugin->boot();
    }

    /**
     * Brain Monkey tracks actions and filters separately, so each row names its kind.
     *
     * @return iterable<string, array{0: 'action'|'filter', 1: string, 2: class-string, 3: string, 4?: int}>
     */
    public static function hooks(): iterable
    {
        yield 'settings menu'          => ['action', 'admin_menu', SettingsPage::class, 'addMenu'];
        yield 'settings save'          => ['action', 'admin_post_orca_dam_save_settings', SettingsPage::class, 'handleSave'];
        yield 'settings assets'        => ['action', 'admin_enqueue_scripts', SettingsPage::class, 'enqueue'];
        yield 'image downsize'         => ['filter', 'image_downsize', ImageDownsizeFilter::class, 'filter'];
        yield 'attachment url'         => ['filter', 'wp_get_attachment_url', ImageDownsizeFilter::class, 'filterUrl'];
        yield 'srcset meta'            => ['filter', 'wp_calculate_image_srcset_meta', MetadataFilter::class, 'filterSrcsetMeta'];
        yield 'srcset'                 => ['filter', 'wp_calculate_image_srcset', MetadataFilter::class, 'filterSrcset'];
        yield 'capture previous'       => ['action', 'pre_post_update', PostObserver::class, 'capturePrevious'];
        yield 'save post'              => ['action', 'save_post', PostObserver::class, 'onSave', 20];
        yield 'rest insert'            => ['action', 'rest_after_insert_post', PostObserver::class, 'onRestInsert'];
        yield 'delete post'            => ['action', 'before_delete_post', PostObserver::class, 'onDelete'];
        yield 'trash post'             => ['action', 'wp_trash_post', PostObserver::class, 'onDelete'];
        yield 'tag sync job'           => ['action', TagSyncJob::HOOK, TagSyncJob::class, 'handle'];
        yield 'block editor bundle'    => ['action', 'enqueue_block_editor_assets', Gutenberg::class, 'enqueueBlockEditor'];
        yield 'media modal bundle'     => ['action', 'wp_enqueue_media', Gutenberg::class, 'enqueueMediaModal'];
        yield 'elementor bundle'       => ['action', 'elementor/editor/after_enqueue_scripts', Elementor::class, 'enqueue'];
        yield 'weekly orphan scan'     => ['action', CronScheduler::HOOK, OrphanScanner::class, 'run'];
    }

    /**
     * @param class-string $service
     */
    #[DataProvider('hooks')]
    public function test_boot_registers_hook(string $kind, string $hook, string $service, string $method, int $priority = 10): void
    {
        $callback = [$this->plugin->get($service), $method];
        $registered = $kind === 'action' ? has_action($hook, $callback) : has_filter($hook, $callback);

        $this->assertTrue(is_callable($callback), "{$service}::{$method} is not callable");
        $this->assertSame($priority, $registered, "{$hook} is not wired to {$service}::{$method}");
    }

    public function test_boot_configures_the_update_checker(): void
    {
        $this->assertNotFalse(has_filter('puc_request_info_result-orca-dam-picker'));
        $this->assertNotFalse(has_filter('puc_vcs_update_detection_strategies-orca-dam-picker', [GitHubUpdater::class, 'releasesOnly']));
    }

    public function test_every_rest_route_is_registered_behind_a_capability(): void
    {
        $routes = [];
        Functions\when('register_rest_route')->alias(function (string $namespace, string $route, array $args) use (&$routes): bool {
            $routes["{$namespace}{$route}"] = $args;
            return true;
        });

        $this->assertIsCallable($this->restInit, 'boot() did not hook rest_api_init');
        ($this->restInit)();

        $expected = [
            'orca/v1/assets/search'      => 'upload_files',
            'orca/v1/assets/(?P<id>\d+)' => 'upload_files',
            'orca/v1/tags'               => 'upload_files',
            'orca/v1/folders'            => 'upload_files',
            'orca/v1/import'             => 'upload_files',
            'orca/v1/rehydrate'          => 'edit_posts',
            'orca/v1/health'             => 'manage_options',
            'orca/v1/broken'             => 'manage_options',
            'orca/v1/scan'               => 'manage_options',
        ];
        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($routes));

        foreach ($routes as $route => $args) {
            $this->assertIsCallable($args['callback'] ?? null, "{$route} has no callback");
            $this->assertIsCallable($args['permission_callback'] ?? null, "{$route} has no permission_callback");

            $checked = [];
            Functions\when('current_user_can')->alias(function (string $capability) use (&$checked): bool {
                $checked[] = $capability;
                return false;
            });

            $this->assertFalse(($args['permission_callback'])(), "{$route} is open to users without the capability");
            $this->assertSame([$expected[$route]], $checked, "{$route} checks the wrong capability");
        }
    }

    public function test_activation_schedules_the_weekly_scan_once(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\expect('wp_schedule_event')->once()->with(\Mockery::type('int'), 'weekly', CronScheduler::HOOK);

        Plugin::onActivate();
    }

    public function test_activation_leaves_an_existing_schedule_alone(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(1_900_000_000);
        Functions\expect('wp_schedule_event')->never();

        Plugin::onActivate();
    }

    public function test_deactivation_clears_both_cron_hooks(): void
    {
        $cleared = [];
        Functions\when('wp_clear_scheduled_hook')->alias(function (string $hook) use (&$cleared): void {
            $cleared[] = $hook;
        });

        Plugin::onDeactivate();

        $this->assertEqualsCanonicalizing([TagSyncJob::HOOK, CronScheduler::HOOK], $cleared);
    }
}
