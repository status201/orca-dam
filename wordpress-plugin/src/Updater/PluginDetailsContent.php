<?php

declare(strict_types=1);

namespace OrcaDam\Updater;

/**
 * Builds the rich HTML rendered inside WordPress's plugin-details modal
 * (Plugins → "View version X details"). PUC reads $info->sections and
 * pipes each key into its own tab; we set description / installation /
 * changelog directly so we don't depend on WP-org-style readme.txt parsing.
 */
final class PluginDetailsContent
{
    /**
     * @return array{description: string, installation: string, changelog: string}
     */
    public static function sections(): array
    {
        $logoUrl = plugins_url('assets/orca-logo.svg', ORCA_DAM_PICKER_FILE);
        $pickerScreenshot = plugins_url('assets/screenshot-picker.png', ORCA_DAM_PICKER_FILE);
        $settingsScreenshot = plugins_url('assets/screenshot-settings.png', ORCA_DAM_PICKER_FILE);

        return [
            'description'  => self::description($logoUrl, $pickerScreenshot, $settingsScreenshot),
            'installation' => self::installation($settingsScreenshot),
            'changelog'    => self::changelog(),
        ];
    }

    private static function description(string $logoUrl, string $pickerScreenshot, string $settingsScreenshot): string
    {
        $card = 'padding:16px;border:1px solid #dcdcde;border-radius:6px;background:#f6f7f7;';
        $featureRow = 'display:flex;align-items:flex-start;gap:10px;margin:0 0 10px 0;';

        return <<<HTML
<div style="display:flex;align-items:center;gap:18px;margin:0 0 24px 0;padding:20px;background:linear-gradient(135deg,#f6f7f7,#fff);border:1px solid #dcdcde;border-radius:8px;">
    <img src="{$logoUrl}" alt="ORCA" style="width:64px;height:64px;flex-shrink:0;" />
    <div>
        <h3 style="margin:0 0 6px 0;font-size:18px;">Your team's DAM, one click away</h3>
        <p style="margin:0;color:#50575e;">Browse, search, and insert assets from ORCA without leaving the editor. Usage tracking happens automatically.</p>
    </div>
</div>

<p>ORCA DAM Picker bolts an extra <strong>ORCA DAM</strong> tab into every WordPress media frame — block editor, classic editor, featured-image picker, and Elementor. Selected images stay hosted on ORCA's S3; WordPress holds only a lightweight attachment shell that points at the canonical URL. When a post is published, the plugin attaches a <code>wp:&lt;site&gt;/post/&lt;id&gt;</code> reference tag to each used asset, so marketing can see exactly where each image is in use from inside ORCA.</p>

<h4 style="margin-top:24px;">Key features</h4>
<ul style="list-style:none;padding:0;margin:0 0 24px 0;">
    <li style="{$featureRow}"><span style="font-size:20px;">📁</span><span><strong>Folder filter.</strong> The picker reads ORCA's folder list directly — pick the folder you want, the grid filters instantly.</span></li>
    <li style="{$featureRow}"><span style="font-size:20px;">🔍</span><span><strong>Live search.</strong> Debounced full-text search across filenames, alt text, tags, and folder paths.</span></li>
    <li style="{$featureRow}"><span style="font-size:20px;">↕️</span><span><strong>Ten sort options.</strong> Newest, recently uploaded, name, size, path — both directions.</span></li>
    <li style="{$featureRow}"><span style="font-size:20px;">⏬</span><span><strong>Paginated loading.</strong> 24 per page with a Load more button, no hard cap.</span></li>
    <li style="{$featureRow}"><span style="font-size:20px;">🏷️</span><span><strong>Automatic usage tags.</strong> Saving a post pushes a reference tag to ORCA — no manual sync.</span></li>
    <li style="{$featureRow}"><span style="font-size:20px;">🔗</span><span><strong>Zero local storage.</strong> Images stay on ORCA's S3. WordPress holds only a tiny pointer row.</span></li>
    <li style="{$featureRow}"><span style="font-size:20px;">🩺</span><span><strong>Broken-asset detection.</strong> Weekly scan flags posts that reference assets deleted from ORCA.</span></li>
    <li style="{$featureRow}"><span style="font-size:20px;">🔐</span><span><strong>Encrypted credentials.</strong> The API token is encrypted at rest using AES-256-GCM derived from your WP <code>AUTH_KEY</code>.</span></li>
</ul>

<h4>Tips</h4>
<div style="{$card}margin-bottom:14px;">
    <strong>The orca logo in the picker is a link.</strong><br>
    Click it to jump straight into the ORCA admin in a new tab — useful when you want to upload a new asset and immediately come back to insert it.
</div>
<div style="{$card}margin-bottom:14px;">
    <strong>Folder &gt; Tag.</strong><br>
    The folder filter is curated and stable; the tag dropdown got removed in 0.3.0 because production ORCA tag counts blow past a thousand quickly. Use folders to scope your view.
</div>
<div style="{$card}margin-bottom:14px;">
    <strong>Insert before publish, tag after.</strong><br>
    The reference-tag sync fires on save. Insert an asset, save as draft, and ORCA already knows about it — no need to publish before the link is recorded.
</div>
<div style="{$card}margin-bottom:14px;">
    <strong>Switch language → Dutch.</strong><br>
    The whole picker and settings UI is translated to Dutch. Change the site language in <em>Settings → General → Site Language</em> and reload.
</div>

<h4>Troubleshooting</h4>
<dl style="margin-bottom:20px;">
    <dt style="font-weight:600;margin-top:14px;">"ORCA returned 401" on Test Connection</dt>
    <dd style="margin:4px 0 0 0;color:#50575e;">The API token is wrong, expired, or for a user without the <code>api</code> role. Generate a fresh one in ORCA → API Docs → API Tokens and paste it in.</dd>
    <dt style="font-weight:600;margin-top:14px;">"ORCA returned 0"</dt>
    <dd style="margin:4px 0 0 0;color:#50575e;">Your WordPress server can't reach ORCA. Open <code>https://your-orca-base-url/api/health</code> in a browser tab on the same network. If that fails, it's a network or DNS issue, not a plugin bug.</dd>
    <dt style="font-weight:600;margin-top:14px;">Picker shows "No assets found" but ORCA has plenty</dt>
    <dd style="margin:4px 0 0 0;color:#50575e;">Check the active folder filter and that the token's user has read access to the folder in question. ORCA enforces per-user folder visibility — the plugin only sees what the token sees.</dd>
    <dt style="font-weight:600;margin-top:14px;">"Update Plugins" shows the new version but installs the old one</dt>
    <dd style="margin:4px 0 0 0;color:#50575e;">This was a bug in 0.3.0 (fixed in 0.4.0). If you're stuck below 0.4.0, deactivate + delete the plugin, then upload the latest zip from <a href="https://github.com/status201/orca-dam/releases">GitHub Releases</a>. Your settings persist in <code>wp_options</code>.</dd>
</dl>

<h4>What it looks like</h4>
<p style="color:#50575e;margin:0 0 8px 0;">The picker, inside the WordPress media frame:</p>
<img src="{$pickerScreenshot}" alt="ORCA DAM picker inside the WordPress media frame" style="max-width:100%;border:1px solid #dcdcde;border-radius:4px;margin-bottom:20px;" />

<p style="color:#50575e;margin:0 0 8px 0;">Settings → ORCA DAM:</p>
<img src="{$settingsScreenshot}" alt="ORCA DAM settings page" style="max-width:100%;border:1px solid #dcdcde;border-radius:4px;" />
HTML;
    }

    private static function installation(string $settingsScreenshot): string
    {
        return <<<HTML
<h4>1. Install the plugin</h4>
<ol>
    <li>Grab the latest <code>orca-dam-picker-X.Y.Z.zip</code> from <a href="https://github.com/status201/orca-dam/releases">GitHub Releases</a>.</li>
    <li>In WordPress admin, go to <strong>Plugins → Add New → Upload Plugin</strong> and upload the zip.</li>
    <li>Click <strong>Activate Plugin</strong>.</li>
</ol>

<h4>2. Get an ORCA API token</h4>
<ol>
    <li>Sign in to ORCA as an admin.</li>
    <li>Go to <strong>API Docs → API Tokens</strong>.</li>
    <li>Create a token for a user with the <code>api</code> role and a recognizable name (e.g. <em>"WordPress — yoursite.com"</em>).</li>
    <li>Copy the token — it's shown only once.</li>
</ol>

<h4>3. Connect the plugin</h4>
<ol>
    <li>In WordPress admin, go to <strong>Settings → ORCA DAM</strong>.</li>
    <li>Set <strong>ORCA base URL</strong> (no trailing slash, e.g. <code>https://dam.example.com</code>).</li>
    <li>Paste the API token.</li>
    <li>Optional: set a <strong>Default folder filter</strong> to scope the picker to one folder by default.</li>
    <li>Click <strong>Save</strong> → then <strong>Test connection</strong>. A green "Connected to ORCA." banner means you're done.</li>
</ol>
<img src="{$settingsScreenshot}" alt="ORCA DAM settings page" style="max-width:100%;border:1px solid #dcdcde;border-radius:4px;margin:8px 0 20px 0;" />

<h4>4. Optional hardening</h4>
<p>Add the following constant to <code>wp-config.php</code> to derive the credentials-encryption key from a dedicated secret instead of <code>AUTH_KEY</code>:</p>
<pre style="background:#f6f7f7;padding:10px;border-radius:4px;overflow:auto;">define('ORCA_ENCRYPTION_KEY', '&lt;32-byte random base64&gt;');</pre>

<h4>5. Use it</h4>
<p>Open any post, insert an Image block, click <strong>Media Library</strong>, switch to the <strong>ORCA DAM</strong> tab, pick an asset, and insert. The orca logo next to the search box opens ORCA in a new tab if you need to upload first.</p>
HTML;
    }

    private static function changelog(): string
    {
        return <<<HTML
<h4>0.4.1</h4>
<ul>
    <li>Picker now reads pagination from Laravel's flat Paginator JSON — the asset count is correct against real ORCA, and Load more actually appears.</li>
</ul>

<h4>0.4.0</h4>
<ul>
    <li>ORCA logo on the plugins list, settings page, and picker.</li>
    <li>Updater no longer chokes on <code>wp-v</code> tag prefixes (the 0.2.0 → 0.3.0 update silently no-op'd before this fix).</li>
    <li>Dropped Action Scheduler from composer — it was never loaded; reference-tag sync runs on WP-Cron via <code>wp_schedule_single_event</code>.</li>
</ul>

<h4>0.3.0</h4>
<ul>
    <li>Picker UX overhaul: folder filter replaces the tags dropdown, all ten ORCA sort options exposed, explicit <em>Load more</em> button, click-to-select feedback on tiles.</li>
</ul>

<h4>0.2.0</h4>
<ul>
    <li>Initial folder-aware picker, settings page, REST proxy hardening, reference-tag sync, broken-asset scan.</li>
</ul>

<h4>0.1.0</h4>
<ul>
    <li>Initial release.</li>
</ul>
HTML;
    }
}
