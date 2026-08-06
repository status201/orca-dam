<?php

namespace App\Console\Commands;

use App\Support\ColumnLimits;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The schema checks the test suite structurally cannot make.
 *
 * Tests run in-memory SQLite (ADR-008), which reports a bare `varchar` with no length and enforces
 * no index key limit. So every width in ColumnLimits and every byte of index budget is invisible to
 * CI: an insert that MariaDB rejects with SQLSTATE 22001 succeeds in every test, and an index
 * MariaDB refuses with errno 1071 is created happily. That is precisely how `assets.copyright`
 * shipped as varchar(255) behind rules allowing 500 for seven months.
 *
 * specs/features/input-validation.md recorded these as manual checks to run by hand against a real
 * MariaDB. A checklist in a document is one nobody runs, so this is that checklist as code.
 *
 * Exit codes are three-valued on purpose:
 *   0  every check passed
 *   1  at least one check FAILED — the schema disagrees with what the application believes
 *   2  the checks could not be RUN, because the driver cannot answer them
 *
 * 1 and 2 are distinguished so that a pipeline can tell "verified wrong" from "not verified".
 * Collapsing them into a single non-zero would make a SQLite deployment look broken; collapsing
 * either into 0 would let a run that verified nothing read as a pass, which is the failure mode
 * this command exists to remove.
 *
 * @see specs/features/input-validation.md
 * @see specs/features/maintenance-commands.md
 */
class VerifySchemaCommand extends Command
{
    protected $signature = 'db:verify-schema';

    protected $description = 'Verify the live schema against ColumnLimits and InnoDB index limits (MySQL/MariaDB only)';

    /**
     * InnoDB's maximum index key length, DYNAMIC or COMPRESSED row format on a 16K page.
     */
    private const MAX_INDEX_KEY_BYTES = 3072;

    /**
     * Key-size contribution of the non-string column types this schema indexes.
     *
     * String columns are measured exactly, from information_schema, and dominate every index here
     * (1020 of assets_folder_filter_index's 1030 bytes). These are the fixed-width remainder; the
     * budget report is therefore an estimate only in its small change, and errs upward where it
     * errs at all.
     *
     * @var array<string, int>
     */
    private const FIXED_WIDTH_BYTES = [
        'tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int' => 4, 'integer' => 4, 'bigint' => 8,
        'float' => 4, 'double' => 8, 'date' => 3, 'time' => 3, 'year' => 1,
        // MySQL 5.6.4+ storage sizes: DATETIME is 5 bytes, not 8, and TIMESTAMP is 4. Laravel's
        // timestamps() and softDeletes() emit TIMESTAMP, so deleted_at and updated_at cost 4 + 1
        // null byte each — the 5 + 1020 + 5 that assets_folder_filter_index is documented at.
        'datetime' => 5, 'timestamp' => 4,
    ];

    private int $failures = 0;

    public function handle(): int
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->components->warn("Cannot verify a {$driver} schema.");
            $this->line('');
            $this->line('  These checks read declared varchar widths, the table row format and InnoDB');
            $this->line('  index key sizes. SQLite reports a bare `varchar` with no length and enforces');
            $this->line('  no index limit, so it can answer none of them — a run here would report');
            $this->line('  success having verified nothing, which is the outcome this command exists');
            $this->line('  to prevent. Point DB_CONNECTION at the MariaDB instance and run it again.');
            $this->line('');

            // Not FAILURE: nothing is known to be wrong. See the exit-code note in the class docblock.
            return 2;
        }

        $database = $connection->getDatabaseName();
        $this->line('');
        $this->components->info("Verifying {$driver} schema: {$database}");

        $this->checkRowFormat();
        $this->checkVarcharWidths();
        $this->checkTextCapacity();
        $this->checkIndexKeyBudget();

        $this->line('');

        if ($this->failures > 0) {
            $this->components->error($this->failures.' check(s) failed. The schema disagrees with the application.');

            return Command::FAILURE;
        }

        $this->components->info('Schema verified.');

        return Command::SUCCESS;
    }

    /**
     * Every table holding a ColumnLimits-declared column must be DYNAMIC.
     *
     * Under COMPACT or REDUNDANT, InnoDB caps an index key at 767 bytes rather than 3072 — which
     * assets.s3_key's 255-character prefix index (1020 bytes) already exceeds — and caps the
     * declared row size such that five varchar(1024) columns fail with errno 1118. Nothing in this
     * repository pins the row format: config/database.php sets 'engine' => null, so the server
     * default applies and this is an assumption until something checks it.
     */
    private function checkRowFormat(): void
    {
        $this->line('');
        $this->line('<options=bold>Row format</> <fg=gray>(DYNAMIC — else the index and row-size limits are far lower)</>');

        foreach ($this->declaredTables() as $table) {
            $format = DB::selectOne(
                'select row_format from information_schema.tables where table_schema = database() and table_name = ?',
                [$table]
            )?->row_format;

            if ($format === null) {
                $this->reportFail($table, 'table not found');

                continue;
            }

            strcasecmp($format, 'Dynamic') === 0
                ? $this->reportPass($table, $format)
                : $this->reportFail($table, "{$format} — expected DYNAMIC");
        }
    }

    /**
     * Every declared varchar width must match the column that actually exists.
     *
     * This is the check that would have caught the original bug on the day it shipped: the rules
     * said 500, the column said 255, and nothing compared them against a real server.
     */
    private function checkVarcharWidths(): void
    {
        $this->line('');
        $this->line('<options=bold>Declared column widths</> <fg=gray>(App\Support\ColumnLimits::CHARS)</>');

        foreach (ColumnLimits::CHARS as $table => $columns) {
            foreach ($columns as $column => $declared) {
                $live = $this->columnInfo($table, $column);

                if ($live === null) {
                    $this->reportFail("{$table}.{$column}", 'column does not exist');

                    continue;
                }

                $actual = (int) $live->character_maximum_length;

                $actual === $declared
                    ? $this->reportPass("{$table}.{$column}", "{$live->data_type}({$actual})")
                    : $this->reportFail("{$table}.{$column}", "{$live->data_type}({$actual}) — ColumnLimits declares {$declared}");
            }
        }
    }

    /**
     * A TEXT column must really be TEXT-family, and hold the byte capacity ColumnLimits assumes.
     *
     * ColumnLimits::fitsText() compares a character cap against these numbers at 4 bytes per
     * character. If a column were quietly a `tinytext` (255 bytes), that arithmetic would be
     * comparing a rule against capacity the column does not have.
     */
    private function checkTextCapacity(): void
    {
        $this->line('');
        $this->line('<options=bold>TEXT capacity</> <fg=gray>(App\Support\ColumnLimits::TEXT_BYTES)</>');

        foreach (ColumnLimits::TEXT_BYTES as $table => $columns) {
            foreach ($columns as $column => $declaredBytes) {
                $live = $this->columnInfo($table, $column);

                if ($live === null) {
                    $this->reportFail("{$table}.{$column}", 'column does not exist');

                    continue;
                }

                $actualBytes = (int) $live->character_octet_length;

                $actualBytes >= $declaredBytes
                    ? $this->reportPass("{$table}.{$column}", "{$live->data_type} ({$actualBytes} bytes)")
                    : $this->reportFail("{$table}.{$column}", "{$live->data_type} holds {$actualBytes} bytes — ColumnLimits assumes {$declaredBytes}");
            }
        }
    }

    /**
     * No index key may exceed InnoDB's limit.
     *
     * Deliberately generic rather than a hardcoded list of the indexes on assets.s3_key. Those are
     * already pinned by name in tests/Feature/S3KeyWidthTest.php, which SQLite can run; what SQLite
     * cannot do is add up the bytes. Measuring every index means the next person to widen an
     * indexed column is told before they deploy, instead of discovering errno 1071 in production —
     * which is the whole class of bug this command is here for, not one instance of it.
     *
     * "Every index" means every B-tree index: see isKeyLengthLimited(). Exempt ones are listed
     * rather than dropped, because an exemption nobody sees reads exactly like a check that passed.
     */
    private function checkIndexKeyBudget(): void
    {
        $this->line('');
        $this->line('<options=bold>Index key budget</> <fg=gray>(InnoDB limit '.self::MAX_INDEX_KEY_BYTES.' bytes)</>');

        $rows = DB::select(
            'select table_name, index_name, index_type, seq_in_index, column_name, sub_part
             from information_schema.statistics
             where table_schema = database() and index_name != \'PRIMARY\'
             order by table_name, index_name, seq_in_index'
        );

        $indexes = [];
        foreach ($rows as $row) {
            $indexes[$row->table_name.'.'.$row->index_name][] = $row;
        }

        if ($indexes === []) {
            $this->reportFail('information_schema.statistics', 'returned no indexes — cannot verify');

            return;
        }

        // Widest label, so the byte column lines up instead of colliding with a long index name.
        $width = max(array_map(strlen(...), array_keys($indexes))) + 2;
        $skipped = [];

        foreach ($indexes as $key => $parts) {
            $type = strtoupper((string) $parts[0]->index_type);

            if (! self::isKeyLengthLimited($type)) {
                $skipped[$key] = $type;

                continue;
            }

            $bytes = 0;
            $shape = [];

            foreach ($parts as $part) {
                $live = $this->columnInfo($part->table_name, $part->column_name);

                if ($live === null) {
                    continue;
                }

                $bytes += self::keyBytes($live, $part->sub_part);
                $shape[] = $part->sub_part !== null
                    ? "{$part->column_name}({$part->sub_part})"
                    : $part->column_name;
            }

            $label = str_pad($key, $width).str_pad($bytes.' bytes', 12).' '.implode(', ', $shape);

            $bytes <= self::MAX_INDEX_KEY_BYTES
                ? $this->reportPass($label, '')
                : $this->reportFail($label, 'over the '.self::MAX_INDEX_KEY_BYTES.'-byte limit');
        }

        // Named, not silently dropped: an unreported exemption is indistinguishable from coverage.
        foreach ($skipped as $key => $type) {
            $this->line('  <fg=gray>–</> '.str_pad($key, $width).'<fg=gray>not key-length limited ('.$type.')</>');
        }
    }

    /**
     * Whether InnoDB's 3072-byte key-prefix limit applies to an index of this type.
     *
     * It applies to B-tree indexes, and only to those. A FULLTEXT index is an inverted index over
     * tokens with an entirely different on-disk structure and no such cap — measuring one means
     * summing the declared width of every column in it, which for `assets_fulltext` over two TEXT
     * columns produced a nonsense 131072 bytes and a false failure. SPATIAL indexes (R-tree) are
     * likewise exempt.
     *
     * The empirical proof is stronger than the documentation: `assets_fulltext` exists on the live
     * server. MySQL refuses to create an index that breaches the limit, so an index that is present
     * cannot be breaching one.
     */
    public static function isKeyLengthLimited(string $indexType): bool
    {
        return strtoupper($indexType) === 'BTREE';
    }

    /**
     * Bytes one column contributes to an index key.
     *
     * String columns are exact: character_octet_length / character_maximum_length gives the real
     * bytes-per-character for the column's own charset (4 under utf8mb4, 1 under ascii), which a
     * prefix length then multiplies. Everything else comes from the fixed-width table, plus one
     * byte when the column is nullable.
     *
     * Public and static so the arithmetic can be tested without a MySQL server. It is the one part
     * of this command that can be wrong *quietly* — a bad multiplier reports a comfortable number
     * for an index that will not fit — and it is also the only part SQLite can exercise, since it
     * is pure: information_schema row in, byte count out.
     */
    public static function keyBytes(object $column, ?int $subPart): int
    {
        $nullByte = strcasecmp($column->is_nullable, 'YES') === 0 ? 1 : 0;
        $maxChars = (int) $column->character_maximum_length;

        if ($maxChars > 0) {
            $bytesPerChar = max(1, (int) ceil(((int) $column->character_octet_length) / $maxChars));

            return (($subPart !== null ? (int) $subPart : $maxChars) * $bytesPerChar) + $nullByte;
        }

        return (self::FIXED_WIDTH_BYTES[strtolower($column->data_type)] ?? 8) + $nullByte;
    }

    /**
     * @var array<string, ?object>
     */
    private array $columnCache = [];

    private function columnInfo(string $table, string $column): ?object
    {
        return $this->columnCache[$table.'.'.$column] ??= DB::selectOne(
            'select data_type, character_maximum_length, character_octet_length, is_nullable
             from information_schema.columns
             where table_schema = database() and table_name = ? and column_name = ?',
            [$table, $column]
        );
    }

    /**
     * @return array<int, string>
     */
    private function declaredTables(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(ColumnLimits::CHARS),
            array_keys(ColumnLimits::TEXT_BYTES),
        )));
    }

    private function reportPass(string $label, string $detail): void
    {
        $this->line('  <fg=green>✓</> '.str_pad($label, 46).' <fg=gray>'.$detail.'</>');
    }

    private function reportFail(string $label, string $detail): void
    {
        $this->failures++;
        $this->line('  <fg=red>✗</> '.str_pad($label, 46).' <fg=red>'.$detail.'</>');
    }
}
