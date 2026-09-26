<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('activitylog.database_connection');
        $table = config('activitylog.table_name') ?? 'activity_log';

        if (! Schema::connection($connection)->hasColumn($table, 'visibility')) {
            Schema::connection($connection)->table($table, function (Blueprint $blueprint) use ($connection, $table) {
                $column = $blueprint->string('visibility', 20)->default('internal')->index();

                if (Schema::connection($connection)->hasColumn($table, 'causer_id')) {
                    $column->after('causer_id');
                }
            });
        }

        $query = DB::connection($connection)->table($table);

        // Priority order (highest wins), applied only to rows still at the
        // column default so re-running this migration is a no-op. Rows with
        // is_internal truthy need no pass — they're already at the default
        // ("internal") and must never be overridden by a stray host/borrower
        // flag also present on the same row:
        // 1. visible_to_borrower / send_to_borrower truthy (and not
        //    is_internal) -> everyone
        // 2. visible_to_host / send_to_host / visible_to_partner truthy -> partner
        // 3. otherwise -> stays internal (the column default)
        (clone $query)
            ->where('visibility', 'internal')
            ->where(function ($notInternal) {
                $notInternal->whereNull('properties->is_internal')
                    ->orWhere('properties->is_internal', false)
                    ->orWhere('properties->is_internal', '0');
            })
            ->where(function ($q) {
                $q->where('properties->visible_to_borrower', true)
                    ->orWhere('properties->visible_to_borrower', '1')
                    ->orWhere('properties->send_to_borrower', true)
                    ->orWhere('properties->send_to_borrower', '1');
            })
            ->update(['visibility' => 'everyone']);

        (clone $query)
            ->where('visibility', 'internal')
            ->where(function ($q) {
                $q->where('properties->visible_to_host', true)
                    ->orWhere('properties->visible_to_host', '1')
                    ->orWhere('properties->send_to_host', true)
                    ->orWhere('properties->send_to_host', '1')
                    ->orWhere('properties->visible_to_partner', true)
                    ->orWhere('properties->visible_to_partner', '1');
            })
            ->update(['visibility' => 'partner']);
    }

    public function down(): void
    {
        $connection = config('activitylog.database_connection');
        $table = config('activitylog.table_name') ?? 'activity_log';

        if (Schema::connection($connection)->hasColumn($table, 'visibility')) {
            Schema::connection($connection)->table($table, function (Blueprint $table) {
                $table->dropColumn('visibility');
            });
        }
    }
};
