#!/usr/bin/env node
/**
 * PreToolUse guard (Bash + PowerShell).
 *
 * Blocks raw laravel-lang publisher commands: they merge official
 * translations OVER existing project values in lang/nl.json with no way to
 * opt out (confirmed in publisher's Helpers/Arr.php::merge). A raw
 * `lang:update` once reverted 26 ORCA translations ("Tags" -> "Labels",
 * passkey strings back to English).
 *
 * Safe alternative: `php artisan lang:safe-update` snapshots nl.json, runs
 * lang:update, and restores any project translations that were overwritten.
 */

let raw = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => { raw += chunk; });
process.stdin.on('end', () => {
    let payload;
    try {
        payload = JSON.parse(raw);
    } catch (_) {
        process.exit(0); // malformed input → don't block
    }

    const command = (payload && payload.tool_input && payload.tool_input.command) || '';

    // The wrapper is always allowed.
    if (/artisan\s+lang:safe-update\b/i.test(command)) {
        process.exit(0);
    }

    // lang:update / lang:reset always rewrite nl.json; lang:add only when
    // (re-)adding the nl locale.
    const dangerous
        = /artisan\s+lang:(update|reset)\b/i.test(command)
            || /artisan\s+lang:add\b[^&|;]*\bnl\b/i.test(command);

    if (!dangerous) {
        process.exit(0);
    }

    const reason =
        'ORCA: raw laravel-lang commands overwrite project translations in ' +
        'lang/nl.json (e.g. "Tags" -> "Labels"). ' +
        "Use 'php artisan lang:safe-update' instead — it runs lang:update " +
        'and then restores any project translations that were stomped.';

    process.stdout.write(JSON.stringify({
        hookSpecificOutput: {
            hookEventName: 'PreToolUse',
            permissionDecision: 'deny',
            permissionDecisionReason: reason,
        },
    }));
    process.exit(0);
});
