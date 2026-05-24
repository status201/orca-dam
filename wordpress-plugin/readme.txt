=== ORCA DAM Picker ===
Contributors: studyflow
Tags: media, dam, asset-management, gutenberg
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.3.0
License: MIT

Browse and insert assets from ORCA DAM directly inside WordPress, with automatic usage tracking via reference tags.

== Description ==

ORCA DAM Picker integrates an ORCA DAM library into the WordPress block editor, classic editor, featured-image picker, and Elementor. Selected images stay hosted on ORCA — WordPress holds only a lightweight attachment shell that points at the ORCA URL. When a post is saved, the plugin attaches a `wp:{site_host}/post/{id}` reference tag to each used asset so marketing can see usage from inside ORCA.

== Installation ==

1. Upload the `orca-dam-picker.zip` archive via Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Visit Settings → ORCA DAM and enter the ORCA base URL and an API token (Sanctum) for a user with the `api` role.
4. Optional: set `define('ORCA_ENCRYPTION_KEY', '...');` in `wp-config.php` to encrypt stored credentials.

== Changelog ==

= 0.1.0 =
* Initial release.
