<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store the subscription date fields as DATE again.
 *
 * WHY
 * These four values are calendar dates, not moments in time. "Paid through
 * 2026-08-31" means the same day to a viewer in New York, Dhaka or Sydney, so
 * there is no time of day to preserve and nothing to convert on the way out.
 *
 * An earlier migration widened them to DATETIME to work around resources that
 * ran them through toTimezone(); the fix belongs on the read side instead —
 * date-only columns are now emitted verbatim (see toDateOnly()), so a DATE
 * column renders as the same calendar day for every timezone.
 *
 * EXISTING ROWS
 * Narrowing DATETIME to DATE drops the time component only; the stored calendar
 * date is unchanged. Rows written under the DATETIME window carry 12:00:00,
 * which truncates to the same date they already displayed as.
 */
return new class extends Migration
{
    private array $columns = [
        'first_payment_date',
        'subscription_start_date',
        'subscription_end_date',
        'expected_end_date',
    ];

    public function up(): void
    {
        Schema::table('subscriptions', function ($table) {
            foreach ($this->columns as $column) {
                $table->date($column)->nullable()->change();
            }
        });

        // The migration this reverts has been deleted, so drop its ledger row —
        // otherwise migrate:status reports a migration with no file and a
        // rollback of that batch fails looking for the class.
        DB::table('migrations')
            ->where('migration', '2026_09_01_090000_change_subscription_date_columns_to_datetime')
            ->delete();
    }

    public function down(): void
    {
        Schema::table('subscriptions', function ($table) {
            foreach ($this->columns as $column) {
                $table->dateTime($column)->nullable()->change();
            }
        });
    }
};
