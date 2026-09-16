<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make `middle_name` comparable with the columns it is concatenated with.
 *
 * 2026_09_15_120000 added `middle_name` with a plain `$table->string(...)`, which
 * emits no CHARACTER SET or COLLATE. MySQL then gives the new column the TABLE's
 * default collation - not the collation of the columns beside it. On the older
 * tables those are not the same thing: `borrowers` still carries a pre-utf8mb4
 * table default, while every one of its name columns was created with an
 * explicit `utf8mb4_unicode_ci`.
 *
 * Borrower search concatenates all three:
 *
 *     CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?
 *
 * Mixing two collations of the SAME charset gives the result coercibility NONE,
 * and comparing that with the bind parameter (COERCIBLE, connection collation)
 * is error 1267, "Illegal mix of collations" - a 500 on every client search.
 *
 * It surfaced only in production because the failure depends on which collation
 * the table default happens to be. Where that default is `utf8mb4_bin` the mix
 * is illegal; where it is `latin1_swedish_ci` MySQL can widen latin1 to utf8mb4
 * and the query quietly succeeds. Same defect, visible on one server and not the
 * other, which is why local testing missed it.
 *
 * Note the single-column predicate (`middle_name LIKE ?`) never failed: a column
 * is IMPLICIT and outranks the literal, so its own collation simply wins. Only
 * the concatenation mixes.
 *
 * This aligns the column with its siblings rather than hardcoding a collation,
 * so it is correct on any server whatever its defaults are, and does nothing
 * where the two already agree.
 *
 * NOTE for whoever adds the next column to `borrowers`: the table default is
 * still not utf8mb4_unicode_ci, so a bare `$table->string()` will reintroduce
 * exactly this bug. Give new string columns on that table an explicit
 * `->collation('utf8mb4_unicode_ci')` until the table default itself is
 * converted - a heavier change than belongs in this fix.
 */
return new class extends Migration
{
    /** Tables that 2026_09_15_120000 added `middle_name` to. */
    private array $tables = [
        'borrowers',
        'funding_applications',
        'funding_application_co_borrowers',
        'embedded_form_submissions',
        'embedded_form_co_borrowers',
    ];

    public function up(): void
    {
        // information_schema and the ALTER syntax below are MySQL-specific, and
        // the test suite runs on SQLite, where collation works differently and
        // nothing here applies.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'middle_name')) {
                continue;
            }

            $middle = $this->column($table, 'middle_name');

            // `first_name` is the reference because it is the column middle_name
            // sits beside and is concatenated with; last_name is the fallback
            // only so a table shaped differently still gets aligned.
            $reference = $this->column($table, 'first_name') ?? $this->column($table, 'last_name');

            if (!$middle || !$reference || $middle->COLLATION_NAME === $reference->COLLATION_NAME) {
                continue;
            }

            // MODIFY replaces the whole definition, so type, nullability,
            // default and comment are all restated from what is there now -
            // omitting any of them would silently drop it.
            DB::statement(sprintf(
                'ALTER TABLE `%s` MODIFY `middle_name` %s CHARACTER SET %s COLLATE %s %s%s%s',
                $table,
                $middle->COLUMN_TYPE,
                $reference->CHARACTER_SET_NAME,
                $reference->COLLATION_NAME,
                $middle->IS_NULLABLE === 'YES' ? 'NULL' : 'NOT NULL',
                $middle->COLUMN_DEFAULT === null ? '' : ' DEFAULT ' . $this->quote($middle->COLUMN_DEFAULT),
                $middle->COLUMN_COMMENT === '' ? '' : ' COMMENT ' . $this->quote($middle->COLUMN_COMMENT)
            ));
        }
    }

    /**
     * Deliberately irreversible.
     *
     * The previous state was a column whose collation disagreed with its
     * neighbours, which broke search. There is nothing to restore, and putting
     * the mismatch back would only reintroduce the 500.
     */
    public function down(): void
    {
        // No-op.
    }

    private function column(string $table, string $column): ?object
    {
        return DB::selectOne(
            'SELECT COLUMN_TYPE, COLLATION_NAME, CHARACTER_SET_NAME, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getDatabaseName(), $table, $column]
        );
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
};
