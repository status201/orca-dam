<?php

declare(strict_types=1);

// Minimal stand-ins for the WordPress classes and constants the plugin type-hints
// against. Brain Monkey fakes functions only.

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
// The plugin checkout sits where WordPress would keep it: wp-content/plugins/<slug>/.
if (! defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', dirname(__DIR__, 3));
}
if (! defined('WPMU_PLUGIN_DIR')) {
    define('WPMU_PLUGIN_DIR', dirname(__DIR__, 3) . '/mu-plugins');
}
if (! defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}
if (! defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}

if (! class_exists('WP_Post')) {
    final class WP_Post
    {
        public int $ID = 0;
        public string $post_title = '';
        public string $post_content = '';
        public string $post_excerpt = '';
        public string $post_status = 'publish';
        public string $post_mime_type = '';
        public string $post_date = '';

        /** @param array<string, mixed> $props */
        public function __construct(array $props = [])
        {
            foreach ($props as $key => $value) {
                $this->$key = $value;
            }
        }
    }
}

if (! class_exists('WP_Error')) {
    final class WP_Error
    {
        public function __construct(
            public string|int $code = '',
            public string $message = '',
            public mixed $data = '',
        ) {
        }
    }
}
