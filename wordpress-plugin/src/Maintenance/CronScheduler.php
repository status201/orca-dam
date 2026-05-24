<?php

declare(strict_types=1);

namespace OrcaDam\Maintenance;

/**
 * Schedules / unschedules the weekly orphan-shell scan and hooks the cron
 * event to OrphanScanner::run().
 */
final class CronScheduler
{
    public const HOOK = 'orca_dam_weekly_scan';

    public function __construct(private readonly OrphanScanner $scanner) {}

    public function register(): void
    {
        add_action(self::HOOK, [$this->scanner, 'run']);
    }

    public static function schedule(): void
    {
        if (! wp_next_scheduled(self::HOOK)) {
            // Run at next Sunday 03:00 UTC.
            $nextSundayAt3 = strtotime('next Sunday 03:00 UTC');
            wp_schedule_event($nextSundayAt3 ?: (time() + WEEK_IN_SECONDS), 'weekly', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }
}
