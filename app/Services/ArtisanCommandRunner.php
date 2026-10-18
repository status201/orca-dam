<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * The System dashboard's command surface: which artisan commands it may run, how a typed command
 * line becomes Artisan::call() arguments, and the suggested-command cards.
 *
 * @see specs/features/system-admin.md REQ-2, REQ-suggested-commands-runnable
 */
class ArtisanCommandRunner
{
    /**
     * Whitelist of allowed artisan commands for security
     */
    private const ALLOWED_COMMANDS = [
        'cache:clear',
        'config:clear',
        'route:clear',
        'view:clear',
        'optimize:clear',
        'optimize',
        'config:cache',
        'route:cache',
        'view:cache',
        'reference-tag:create',
        'storage:link',
        'uploads:cleanup',
        'queue:retry',
        'queue:flush',
        'queue:restart',
        'queue:work',
        'migrate:status',
        'migrate:rollback',
        'migrate',
        'migrate --force',
        'two-factor:status',
        'two-factor:disable',
        'passkeys:list',
        'passkeys:revoke',
        'assets:backfill-etags',
        'assets:deduplicate',
        'assets:deduplicate --force',
        'assets:verify-integrity',
        'assets:audit-content-types',
        'token:list',
        'db:verify-schema',
    ];

    /**
     * Commands whose bare words are a positional argument, and that argument's name. Every other
     * command gets only its --options and 2-character short flags; a bare word is dropped, since
     * there is no name to pass it under.
     */
    private const POSITIONAL_ARGUMENTS = [
        'queue:retry' => 'id',            // queue:retry {id?*} — 'all' or job ids
        'reference-tag:create' => 'names', // reference-tag:create {names?*}
    ];

    /**
     * Execute whitelisted artisan command
     */
    public function executeCommand(string $command, array $parameters = []): array
    {
        // Parse command to get base command name
        $parts = explode(' ', trim($command));
        $baseCommand = $parts[0];

        if (! $this->isAllowed($command)) {
            Log::warning("Attempted to execute non-whitelisted command: {$command}", [
                'user_id' => auth()->id(),
            ]);

            return [
                'success' => false,
                'output' => '',
                'error' => "Command '{$baseCommand}' is not allowed for security reasons.",
            ];
        }

        try {
            // Log the command execution
            Log::info("System admin executing command: {$command}", [
                'parameters' => $parameters,
                'user_id' => auth()->id(),
            ]);

            // Handle special command cases that need specific argument mapping
            $commandArgs = $this->parseCommandArguments($baseCommand, $parts);

            // Execute via Artisan facade (safer than shell)
            $exitCode = Artisan::call($baseCommand, array_merge($commandArgs, $parameters));
            $output = Artisan::output();

            $success = $exitCode === 0;

            if ($success && empty(trim($output))) {
                $output = __('Command executed successfully');
            }

            return [
                'success' => $success,
                'output' => $output,
                'error' => ! $success ? "Command exited with code {$exitCode}" : null,
                'exit_code' => $exitCode,
            ];

        } catch (\Exception $e) {
            Log::error("Command execution failed: {$command}", [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            return [
                'success' => false,
                'output' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Whether a command may run from the dashboard: the full command or its base command is on
     * the whitelist. Also what keeps every suggested card runnable (system-admin
     * REQ-suggested-commands-runnable).
     */
    public function isAllowed(string $command): bool
    {
        $command = trim($command);
        $baseCommand = explode(' ', $command)[0];

        return in_array($command, self::ALLOWED_COMMANDS, true)
            || in_array($baseCommand, self::ALLOWED_COMMANDS, true);
    }

    /**
     * Map a command line's words (after the command name) onto Artisan::call() arguments.
     *
     * @param  list<string>  $parts  the command line split on spaces, command name first
     * @return array<string, mixed>
     */
    private function parseCommandArguments(string $baseCommand, array $parts): array
    {
        $positionalName = self::POSITIONAL_ARGUMENTS[$baseCommand] ?? null;
        $arguments = [];
        $positional = [];

        foreach (array_slice($parts, 1) as $arg) {
            if (str_starts_with($arg, '--')) {
                // --option=value, or a bare --flag
                [$option, $value] = array_pad(explode('=', $arg, 2), 2, true);
                $arguments[$option] = $value;
            } elseif ($positionalName !== null) {
                $positional[] = $arg;
            } elseif (str_starts_with($arg, '-') && strlen($arg) === 2) {
                $arguments[$arg] = true;
            }
        }

        if ($positional !== []) {
            $arguments[$positionalName] = $positional;
        }

        return $arguments;
    }

    /**
     * The System page's command cards. Every one must pass isAllowed() once a <placeholder> is
     * filled in (system-admin.md REQ-suggested-commands-runnable).
     */
    public function suggestedCommands(): array
    {
        return [
            [
                'command' => 'assets:backfill-etags',
                'description' => 'Fetch missing etags from S3 for assets without one',
                'category' => 'Assets',
            ],
            [
                'command' => 'assets:deduplicate',
                'description' => 'Dry-run: find duplicate assets by etag',
                'category' => 'Assets',
            ],
            [
                'command' => 'assets:deduplicate --force',
                'description' => 'Soft-delete duplicate assets (keeps oldest, skips referenced)',
                'category' => 'Assets',
            ],
            [
                'command' => 'assets:verify-integrity',
                'description' => 'Queue S3 integrity checks for every asset (flags missing files)',
                'category' => 'Assets',
            ],
            [
                'command' => 'cache:clear',
                'description' => 'Clear application cache',
                'category' => 'Cache',
            ],
            [
                'command' => 'config:clear',
                'description' => 'Remove configuration cache',
                'category' => 'Cache',
            ],
            [
                'command' => 'route:clear',
                'description' => 'Remove route cache',
                'category' => 'Cache',
            ],
            [
                'command' => 'view:clear',
                'description' => 'Clear compiled views',
                'category' => 'Cache',
            ],
            [
                'command' => 'optimize:clear',
                'description' => 'Clear all cached bootstrap files',
                'category' => 'Optimization',
            ],
            [
                'command' => 'optimize',
                'description' => 'Cache framework bootstrap files',
                'category' => 'Optimization',
            ],
            [
                'command' => 'config:cache',
                'description' => 'Cache configuration files',
                'category' => 'Cache',
            ],
            [
                'command' => 'route:cache',
                'description' => 'Cache route registrations',
                'category' => 'Cache',
            ],
            [
                'command' => 'view:cache',
                'description' => 'Cache compiled views',
                'category' => 'Cache',
            ],
            [
                'command' => 'reference-tag:create <name>',
                'description' => 'Pre-create a reference tag (replace <name>) so editors can attach it from the asset edit page',
                'category' => 'Tags',
            ],
            [
                'command' => 'passkeys:list',
                'description' => 'List registered passkeys with owners and last-used timestamps',
                'category' => 'Passkeys',
            ],
            [
                'command' => 'passkeys:revoke <id>',
                'description' => 'Revoke a passkey by ID, or use --user=email to revoke all',
                'category' => 'Passkeys',
            ],
            [
                'command' => 'two-factor:status',
                'description' => 'List users and their two-factor authentication status',
                'category' => '2FA',
            ],
            [
                'command' => 'uploads:cleanup',
                'description' => 'Clean stale upload sessions',
                'category' => 'Maintenance',
            ],
            [
                'command' => 'queue:flush',
                'description' => 'Delete all failed jobs',
                'category' => 'Queue',
            ],
            [
                'command' => 'queue:restart',
                'description' => 'Restart queue workers',
                'category' => 'Queue',
            ],
            [
                'command' => 'migrate:status',
                'description' => 'Check migration status',
                'category' => 'Database',
            ],
            [
                'command' => 'assets:audit-content-types',
                'description' => 'Dry-run: find stored objects served with a Content-Type their extension does not call for (run with --fix to repair)',
                'category' => 'Assets',
            ],
            [
                'command' => 'db:verify-schema',
                'description' => 'Check the live MySQL/MariaDB schema against column limits and index sizes',
                'category' => 'Database',
            ],
            [
                'command' => 'token:list',
                'description' => 'List API tokens with their owners and last-used dates',
                'category' => 'API',
            ],
        ];
    }
}
