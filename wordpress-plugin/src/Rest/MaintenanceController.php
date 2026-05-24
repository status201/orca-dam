<?php

declare(strict_types=1);

namespace OrcaDam\Rest;

use OrcaDam\Maintenance\CronScheduler;
use OrcaDam\Maintenance\OrphanScanner;
use WP_REST_Response;

final class MaintenanceController
{
    public function __construct(private readonly OrphanScanner $scanner) {}

    public function register(): void
    {
        register_rest_route('orca/v1', '/broken', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handleList'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
        ]);

        register_rest_route('orca/v1', '/scan', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleScan'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
        ]);
    }

    public function handleList(): WP_REST_Response
    {
        return new WP_REST_Response([
            'count' => $this->scanner->brokenCount(),
            'items' => $this->scanner->brokenItems(50),
        ], 200);
    }

    public function handleScan(): WP_REST_Response
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(CronScheduler::HOOK, [], 'orca-dam');
        } else {
            wp_schedule_single_event(time(), CronScheduler::HOOK);
        }
        return new WP_REST_Response(['queued' => true], 202);
    }
}
