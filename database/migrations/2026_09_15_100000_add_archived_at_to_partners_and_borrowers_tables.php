<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archiving: keep a record out of the working lists without deleting it.
 *
 * Deliberately NOT SoftDeletes. Both models already use that trait, so deleted_at
 * is taken and means "deleted" - reusing it would conflate two different states.
 * More importantly SoftDeletes applies a GLOBAL scope, which hides a row from every
 * query in the system; the whole point of archiving here is that the record stays
 * findable by search. A plain nullable timestamp with explicit scopes inverts that:
 * lists opt IN to hiding archived rows, and nothing else silently loses them.
 *
 * archived_by records who did it, so the action is accountable and can be shown
 * alongside the restore control.
 *
 * Archiving is presentation-only by design: it does not affect login, billing or
 * any access decision. Those key on `status`, which archiving never touches.
 */
return new class extends Migration
{
    private array $tables = ['partners', 'borrowers'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'archived_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                // Indexed: every default list query filters on it.
                $blueprint->timestamp('archived_at')->nullable()->index();

                // nullOnDelete so removing a staff account never takes the
                // archived record down with it.
                $blueprint->foreignId('archived_by')
                    ->nullable()
                    ->after('archived_at')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'archived_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (Schema::hasColumn($table, 'archived_by')) {
                    $blueprint->dropConstrainedForeignId('archived_by');
                }

                $blueprint->dropColumn('archived_at');
            });
        }
    }
};
