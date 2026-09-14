<?php

use Illuminate\Foundation\Console\ServeCommand;

/**
 * `artisan serve --env=X` does not inherit the environment: it passes through only
 * ServeCommand::$passthroughVariables and explicitly unsets everything else in the
 * server process. The temp directory is not on Laravel's list, so on Windows
 * GetTempPath() falls through to the Windows directory and every multipart request
 * dies at request startup — a 422 with no exception and nothing in the log.
 *
 * AppServiceProvider adds them back. This asserts that, because the failure is
 * invisible on Linux (PHP falls back to /tmp) and would therefore sail through CI.
 *
 * Spec: specs/features/e2e-testing.md REQ-1.
 */
test('the dev server passes the temp directory through to the served process', function () {
    expect(ServeCommand::$passthroughVariables)->toContain('TMP', 'TEMP', 'TMPDIR');
});

test('adding the temp variables did not drop any of Laravel own passthroughs', function () {
    expect(ServeCommand::$passthroughVariables)->toContain('APP_ENV', 'PATH', 'SYSTEMROOT');

    expect(array_unique(ServeCommand::$passthroughVariables))
        ->toHaveCount(count(ServeCommand::$passthroughVariables));
});
