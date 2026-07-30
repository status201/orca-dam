<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop the `users.role` default of 'editor' — see specs/features/authentication.md REQ-8.
 *
 * The removed self-service register route did not grant editor by asking for it; it granted
 * editor by *omitting* the role, and the column default filled it in. Making the column
 * NOT NULL with no default turns that silence into a driver error, which covers every
 * creation path (firstOrCreate, raw inserts, a future SSO or invite flow) rather than only
 * the `User::create(` call sites a source scan can spot.
 *
 * SQLite cannot ALTER a column, and `->change()` is not an option here: 2026_01_26_111545
 * recreated `users` by hand with an inline `email VARCHAR(255) NOT NULL UNIQUE`, so the
 * unique index is SQLite's implicit `sqlite_autoindex_users_1`. Laravel's change()
 * table-recreate replays introspected indexes by name and SQLite reserves that one
 * ("object name reserved for internal use"), so the table is recreated here instead — from
 * its own stored DDL, with only the role column's trailing constraint rewritten.
 */
return new class extends Migration
{
    /**
     * The SQLite path drops and recreates `users`, which needs `PRAGMA foreign_keys = off`
     * (`assets.user_id` references it with ON DELETE RESTRICT). A pragma is a no-op inside
     * a transaction, so this migration must not be wrapped in one.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $unroled = DB::table('users')->whereNull('role')->count();

        if ($unroled > 0) {
            // Deliberately not backfilled: every role grants strictly more than NULL does,
            // so picking one here would promote an account — the exact failure this
            // migration exists to prevent. No code path writes NULL, so this should never
            // fire; if it does, an operator assigns the role and re-runs.
            throw new RuntimeException(
                "Refusing to drop the users.role default: {$unroled} user(s) have a NULL role. "
                ."Assign each one an explicit role ('editor', 'admin' or 'api') and re-run this migration."
            );
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rewriteSqliteRoleConstraint('NOT NULL');
        } else {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('editor', 'admin', 'api') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rewriteSqliteRoleConstraint("DEFAULT 'editor'");
        } else {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('editor', 'admin', 'api') DEFAULT 'editor'");
        }
    }

    /**
     * Recreate `users` with `$constraint` replacing whatever trails the role column's
     * CHECK clause, preserving every row, column and explicitly created index.
     */
    private function rewriteSqliteRoleConstraint(string $constraint): void
    {
        $current = DB::selectOne("select sql from sqlite_master where type = 'table' and name = 'users'")->sql;

        $ddl = preg_replace(
            '/(role\s+VARCHAR\(255\)\s+CHECK\(role IN \([^)]*\)\))[^,]*/i',
            '$1 '.$constraint,
            $current,
            1,
            $matched
        );

        if ($matched !== 1) {
            throw new RuntimeException(
                'Cannot rewrite users.role: the stored SQLite DDL is not the shape this migration '
                ."expects (one `role VARCHAR(255) CHECK(role IN (...))` definition, found {$matched}). "
                ."Rewrite it by hand. DDL:\n{$current}"
            );
        }

        // `sql is null` marks the implicit autoindex behind `email ... UNIQUE`; it comes back
        // with the table definition and must not be replayed by name.
        $indexes = DB::select(
            "select sql from sqlite_master where type = 'index' and tbl_name = 'users' and sql is not null"
        );

        $columns = collect(DB::select('pragma table_info(users)'))
            ->map(fn ($column) => '"'.$column->name.'"')
            ->implode(', ');

        DB::statement('PRAGMA foreign_keys = off');

        try {
            DB::statement('DROP TABLE IF EXISTS users_role_rewrite');
            DB::statement(preg_replace('/^CREATE TABLE "?users"?/i', 'CREATE TABLE "users_role_rewrite"', $ddl, 1));
            DB::statement("INSERT INTO users_role_rewrite ({$columns}) SELECT {$columns} FROM users");
            DB::statement('DROP TABLE users');
            DB::statement('ALTER TABLE users_role_rewrite RENAME TO users');

            foreach ($indexes as $index) {
                DB::statement($index->sql);
            }
        } finally {
            DB::statement('PRAGMA foreign_keys = on');
        }
    }
};
