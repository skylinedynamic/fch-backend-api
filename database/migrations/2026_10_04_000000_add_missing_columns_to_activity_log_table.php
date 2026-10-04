<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds activity_log.deleted_at, which only ever reached fresh installs.
 *
 * ActivityLog uses SoftDeletes, so Eloquent appends
 * "activity_log.deleted_at is null" to every query it builds — and deleting a
 * manual activity is meant to hide the row while keeping the audit trail
 * (ActivityLogService::destroy calls $log->delete()). Without the column, every
 * read of the Activity Log fails with an unknown-column error instead.
 *
 * The column was declared by adding softDeletes() to the already-shipped create
 * migration (2025_11_06_053230_create_activity_log_table) rather than in a
 * migration of its own. A migration whose filename is already recorded in the
 * migrations table never runs again, so no existing database ever received it,
 * while migrate:fresh and the test suite build the table from the current file
 * and look perfectly healthy.
 *
 * The guard makes this a no-op where the column is already present, so it is
 * safe on fresh installs and on every database migrated before that edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('activitylog.database_connection');
        $table = config('activitylog.table_name') ?? 'activity_log';
        $schema = Schema::connection($connection);

        if (! $schema->hasTable($table) || $schema->hasColumn($table, 'deleted_at')) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint) {
            $blueprint->softDeletes();
        });
    }

    public function down(): void
    {
        $connection = config('activitylog.database_connection');
        $table = config('activitylog.table_name') ?? 'activity_log';
        $schema = Schema::connection($connection);

        if (! $schema->hasTable($table) || ! $schema->hasColumn($table, 'deleted_at')) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint) {
            $blueprint->dropSoftDeletes();
        });
    }
};
