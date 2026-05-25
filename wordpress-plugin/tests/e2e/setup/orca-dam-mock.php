<?php
/**
 * Plugin Name: ORCA DAM Mock Transport
 * Description: MU-plugin that swaps ORCA HTTP calls for canned fixture responses.
 *              Only activates when ORCA_DAM_MOCK is set in the environment.
 *
 * Place at wp-content/mu-plugins/orca-dam-mock.php in the test WordPress instance.
 */

declare(strict_types=1);

if (! getenv('ORCA_DAM_MOCK') && ! (defined('ORCA_DAM_MOCK') && constant('ORCA_DAM_MOCK'))) {
    return;
}

add_filter('orca_dam_transport', static function ($_default) {
    return new class implements \OrcaDam\Api\Transport\Transport {
        private array $fixtures;
        /** @var list<array{path: string, body: array}> */
        public static array $calls = [];

        public function __construct()
        {
            $path = __DIR__ . '/orca-fixtures.json';
            $this->fixtures = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        }

        public function request(string $method, string $url, array $query = [], ?array $body = null, array $headers = []): \OrcaDam\Api\Transport\TransportResponse
        {
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            $entry = ['method' => $method, 'path' => $path, 'query' => $query, 'body' => $body];

            // Append to the persisted log. PHP static state is per-request — if we
            // only wrote self::$calls we'd overwrite history from prior requests.
            // Read-modify-write keeps the full call history across the whole test.
            $persisted = (array) get_option('orca_dam_mock_calls', []);
            $persisted[] = $entry;
            update_option('orca_dam_mock_calls', $persisted, false);
            self::$calls = $persisted;

            if (str_ends_with($path, '/api/health')) {
                return new \OrcaDam\Api\Transport\TransportResponse(200, ['status' => 'ok']);
            }
            if (str_ends_with($path, '/api/assets/search')) {
                return new \OrcaDam\Api\Transport\TransportResponse(200, [
                    'data' => $this->fixtures['assets'] ?? [],
                    'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 24, 'total' => count($this->fixtures['assets'] ?? [])],
                ]);
            }
            if (preg_match('|/api/assets/(\d+)$|', $path, $m)) {
                $asset = collect_first($this->fixtures['assets'] ?? [], fn ($a) => (int) ($a['id'] ?? 0) === (int) $m[1]);
                if ($asset) {
                    return new \OrcaDam\Api\Transport\TransportResponse(200, $asset);
                }
                return new \OrcaDam\Api\Transport\TransportResponse(404, ['message' => 'Not found']);
            }
            if (str_ends_with($path, '/api/tags')) {
                return new \OrcaDam\Api\Transport\TransportResponse(200, ['data' => $this->fixtures['tags'] ?? []]);
            }
            if (str_ends_with($path, '/api/reference-tags')) {
                return new \OrcaDam\Api\Transport\TransportResponse(200, ['message' => 'ok']);
            }
            return new \OrcaDam\Api\Transport\TransportResponse(404, ['message' => 'Mock has no canned response for ' . $path]);
        }
    };
});

if (! function_exists('collect_first')) {
    function collect_first(array $items, callable $predicate)
    {
        foreach ($items as $item) {
            if ($predicate($item)) {
                return $item;
            }
        }
        return null;
    }
}

// Expose a debug endpoint that returns the recorded call log.
add_action('rest_api_init', static function () {
    register_rest_route('orca-mock/v1', '/calls', [
        'methods'             => 'GET',
        'callback'            => static fn () => new \WP_REST_Response((array) get_option('orca_dam_mock_calls', []), 200),
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('orca-mock/v1', '/reset', [
        'methods'             => 'POST',
        'callback'            => static function () {
            delete_option('orca_dam_mock_calls');
            return new \WP_REST_Response(['reset' => true], 200);
        },
        'permission_callback' => '__return_true',
    ]);
    // Run Action Scheduler's pending queue synchronously. Hitting /wp-cron.php
    // schedules the AS runner but AS often defers to a loopback HTTP request,
    // which means tests would have to poll. Calling the runner directly here
    // processes every due action before the response returns.
    register_rest_route('orca-mock/v1', '/run-actions', [
        'methods'             => 'POST',
        'callback'            => static function () {
            $info = ['as_class_exists' => class_exists('ActionScheduler_QueueRunner')];

            // Drain AS's queue if AS is loaded.
            if ($info['as_class_exists']) {
                \ActionScheduler_QueueRunner::instance()->run('Async Request');
            }

            // Drain WP-Cron synchronously. PostObserver falls back to
            // wp_schedule_single_event when AS isn't loaded, so the job we care
            // about may be sitting in _get_cron_array, not in AS.
            $info['cron_processed'] = [];
            $crons = _get_cron_array() ?: [];
            foreach ($crons as $timestamp => $hooks) {
                if ($timestamp > microtime(true)) {
                    continue;
                }
                foreach ($hooks as $hook => $events) {
                    foreach ($events as $event) {
                        $info['cron_processed'][] = $hook;
                        do_action_ref_array($hook, $event['args']);
                        wp_unschedule_event($timestamp, $hook, $event['args']);
                    }
                }
            }

            return new \WP_REST_Response($info, 200);
        },
        'permission_callback' => '__return_true',
    ]);
});

// Seed credentials so the picker thinks ORCA is configured.
add_action('init', static function () {
    // The orca-dam-picker plugin may not be loaded yet (e.g. during wp-env's
    // initial `wp core install` step, which runs before plugin activation).
    // Without the plugin's autoloader, OrcaDam\Settings\Encryption is missing
    // and instantiation would fatal — skip and let a later request seed.
    if (! class_exists(\OrcaDam\Settings\Encryption::class)) {
        return;
    }
    if (get_option('orca_dam_base_url') !== 'https://mock.orca.test') {
        update_option('orca_dam_base_url', 'https://mock.orca.test', false);
    }
    // Stash a fake encrypted token directly so we don't need to round-trip through the UI.
    if (get_option('orca_dam_token_encrypted') === false) {
        $enc = new \OrcaDam\Settings\Encryption();
        update_option('orca_dam_token_encrypted', $enc->encrypt('mock-token'), false);
    }
});
