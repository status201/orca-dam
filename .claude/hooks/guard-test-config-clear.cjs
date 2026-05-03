#!/usr/bin/env node
/**
 * PreToolUse Bash guard.
 *
 * Blocks any Bash command that runs the Laravel/Pest test suite without first
 * clearing the config cache in the same command line.
 *
 * Why this exists: phpunit.xml sets DB_DATABASE=:memory:, but a cached
 * bootstrap/cache/config.php overrides those env entries. RefreshDatabase
 * then runs against the real dev sqlite file (database/database.sqlite) and
 * wipes it. Once bitten, twice guarded.
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

    // Match the three ways to invoke the test suite in this project.
    const runsTests = /(php\s+artisan\s+test\b|vendor[\\\/]bin[\\\/]pest\b|vendor[\\\/]bin[\\\/]phpunit\b)/i.test(command);
    if (!runsTests) {
        process.exit(0);
    }

    // Allow if the same command already clears the config cache.
    const clearsConfig = /artisan\s+config:clear/i.test(command);
    if (clearsConfig) {
        process.exit(0);
    }

    const reason =
        "ORCA: testing requires 'php artisan config:clear && ...' first — " +
        'otherwise a stale bootstrap/cache/config.php can point RefreshDatabase ' +
        'at the dev database/database.sqlite and wipe it. ' +
        'Re-run as: php artisan config:clear && ' + command;

    process.stdout.write(JSON.stringify({
        hookSpecificOutput: {
            hookEventName: 'PreToolUse',
            permissionDecision: 'deny',
            permissionDecisionReason: reason,
        },
    }));
    process.exit(0);
});
