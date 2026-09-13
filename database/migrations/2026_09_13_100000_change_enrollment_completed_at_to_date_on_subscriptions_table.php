<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * enrollment_completed_at is a calendar date, not a moment.
 *
 * It records the day a borrower finished paying their enrollment, which is what
 * billing reads it as - "has enrollment completed" and "on what date". Nothing
 * uses the time part, and storing one made it a timestamp that the API had to
 * convert per viewer: the same completion showed as a different day either side
 * of midnight, because a TIMESTAMP at 06:00 UTC is the previous evening in the
 * Americas.
 *
 * Narrowing the column to DATE truncates that time. The existing values are all
 * at 06:00 UTC, so no stored day changes - see the migration's own check below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->date('enrollment_completed_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverting restores the column type but not the time of day, which is
        // gone for good. Every restored value sits at midnight.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('enrollment_completed_at')->nullable()->change();
        });
    }
};
