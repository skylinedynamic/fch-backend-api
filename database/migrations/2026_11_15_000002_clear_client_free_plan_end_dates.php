<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A client's Limited Free Plan has no end date (decision 2026-11-15, after
 * R3-BUG-026). Dates saved through the API, and the 30 days a lead converted
 * to a free client used to get, are cleared. A partner's free trial is
 * untouched (partners.free_trail_end_date).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('borrowers')->whereNotNull('free_trail_end_date')->update(['free_trail_end_date' => null]);
    }

    public function down(): void
    {
        // The cleared dates have no meaning any more; nothing to restore.
    }
};
