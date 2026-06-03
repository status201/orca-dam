<?php

declare(strict_types=1);

namespace OrcaDam;

use OrcaDam\Api\Cache;
use OrcaDam\Api\OrcaClient;
use OrcaDam\Api\Transport\WpHttpTransport;
use OrcaDam\Attachments\ImageDownsizeFilter;
use OrcaDam\Attachments\MetadataFilter;
use OrcaDam\Attachments\ShellFactory;
use OrcaDam\Editors\Elementor;
use OrcaDam\Editors\Gutenberg;
use OrcaDam\Maintenance\CronScheduler;
use OrcaDam\Maintenance\OrphanScanner;
use OrcaDam\Rest\AttachmentImportController;
use OrcaDam\Rest\HealthController;
use OrcaDam\Rest\MaintenanceController;
use OrcaDam\Rest\ProxyFoldersController;
use OrcaDam\Rest\ProxySearchController;
use OrcaDam\Rest\ProxyTagsController;
use OrcaDam\Rest\RehydrateController;
use OrcaDam\Settings\CredentialStore;
use OrcaDam\Settings\Encryption;
use OrcaDam\Settings\SettingsPage;
use OrcaDam\Updater\GitHubUpdater;
use OrcaDam\Usage\ContentScanner;
use OrcaDam\Usage\PostObserver;
use OrcaDam\Usage\TagSyncJob;

/**
 * Minimal service container — registers services lazily and wires up WordPress hooks.
 */
final class Plugin
{
    private static ?self $instance = null;

    /** @var array<string, object> */
    private array $services = [];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        load_plugin_textdomain('orca-dam-picker', false, dirname(plugin_basename(ORCA_DAM_PICKER_FILE)) . '/languages');

        $this->get(SettingsPage::class)->register();
        $this->get(ImageDownsizeFilter::class)->register();
        $this->get(MetadataFilter::class)->register();
        $this->get(PostObserver::class)->register();
        $this->get(TagSyncJob::class)->register();
        $this->get(Gutenberg::class)->register();
        $this->get(Elementor::class)->register();
        $this->get(CronScheduler::class)->register();
        $this->get(GitHubUpdater::class)->register();

        add_action('rest_api_init', function (): void {
            $this->get(ProxySearchController::class)->register();
            $this->get(ProxyTagsController::class)->register();
            $this->get(ProxyFoldersController::class)->register();
            $this->get(AttachmentImportController::class)->register();
            $this->get(HealthController::class)->register();
            $this->get(RehydrateController::class)->register();
            $this->get(MaintenanceController::class)->register();
        });
    }

    public static function onActivate(): void
    {
        CronScheduler::schedule();
    }

    public static function onDeactivate(): void
    {
        wp_clear_scheduled_hook(TagSyncJob::HOOK);
        CronScheduler::unschedule();
    }

    /**
     * Resolve a singleton service.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        /** @psalm-suppress MixedReturnStatement */
        return $this->services[$id] ??= $this->build($id);
    }

    private function build(string $id): object
    {
        return match ($id) {
            Encryption::class            => new Encryption(),
            CredentialStore::class       => new CredentialStore($this->get(Encryption::class)),
            Cache::class                 => new Cache(),
            WpHttpTransport::class       => new WpHttpTransport(),
            OrcaClient::class            => new OrcaClient(
                /**
                 * Filter `orca_dam_transport` to swap in a fake Transport during tests.
                 * Production never hooks this; the default WpHttpTransport is returned.
                 */
                apply_filters('orca_dam_transport', $this->get(WpHttpTransport::class)),
                $this->get(CredentialStore::class),
                $this->get(Cache::class),
            ),
            ContentScanner::class        => new ContentScanner(),
            ShellFactory::class          => new ShellFactory($this->get(OrcaClient::class)),
            ImageDownsizeFilter::class   => new ImageDownsizeFilter(),
            MetadataFilter::class        => new MetadataFilter(),
            PostObserver::class          => new PostObserver($this->get(ContentScanner::class)),
            TagSyncJob::class            => new TagSyncJob($this->get(OrcaClient::class)),
            SettingsPage::class          => new SettingsPage($this->get(CredentialStore::class)),
            ProxySearchController::class => new ProxySearchController($this->get(OrcaClient::class)),
            ProxyTagsController::class   => new ProxyTagsController($this->get(OrcaClient::class)),
            ProxyFoldersController::class => new ProxyFoldersController($this->get(OrcaClient::class)),
            AttachmentImportController::class
                                         => new AttachmentImportController($this->get(ShellFactory::class)),
            HealthController::class      => new HealthController($this->get(OrcaClient::class)),
            RehydrateController::class   => new RehydrateController($this->get(OrcaClient::class), $this->get(ShellFactory::class)),
            OrphanScanner::class         => new OrphanScanner($this->get(OrcaClient::class)),
            CronScheduler::class         => new CronScheduler($this->get(OrphanScanner::class)),
            MaintenanceController::class => new MaintenanceController($this->get(OrphanScanner::class)),
            Gutenberg::class             => new Gutenberg($this->get(CredentialStore::class)),
            Elementor::class             => new Elementor($this->get(CredentialStore::class)),
            GitHubUpdater::class         => new GitHubUpdater(),
            default                      => throw new \RuntimeException("Unknown service: {$id}"),
        };
    }
}
