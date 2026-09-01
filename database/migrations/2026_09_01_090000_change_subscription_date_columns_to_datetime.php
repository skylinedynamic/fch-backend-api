<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store the subscription date fields as DATETIME instead of DATE.
 *
 * WHY
 * These four values are rendered through toTimezone(), which converts the stored
 * value into the caller's timezone (X-Timezone header, sent by both front-ends as
 * the browser's real zone) and then formats it as Y-m-d. A DATE column reads back
 * as midnight, so for any timezone behind UTC the conversion lands on the previous
 * day:
 *
 *   stored 2026-08-31 (DATE -> 2026-08-31 00:00:00 UTC)
 *     -> America/New_York    2026-08-30   <- a day early
 *     -> America/Los_Angeles 2026-08-30   <- a day early
 *
 * Keeping the real time of day makes the conversion land on the correct calendar
 * day. The API still emits date-only strings; only what we persist changes.
 *
 * EXISTING ROWS
 * MySQL widens DATE to DATETIME at midnight, which would leave historic rows still
 * displaying a day early. There is no true time-of-day to recover for them, so
 * their time is set to 12:00:00 — the one value that renders as the intended
 * calendar date in every timezone from UTC-12 to UTC+11. Only the time component
 * is touched; no date moves.
 *
 * BILLING IS NOT AFFECTED: every scheduling comparison on expected_end_date uses
 * whereDate()/startOfDay(), which ignore the time component.
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
                $table->dateTime($column)->nullable()->change();
            }
        });

        // Move historic midnight values to midday so they keep rendering as the
        // date they were always meant to represent, whatever the viewer's zone.
        foreach ($this->columns as $column) {
            DB::table('subscriptions')
                ->whereNotNull($column)
                ->whereRaw("TIME({$column}) = '00:00:00'")
                ->update([$column => DB::raw("DATE_ADD({$column}, INTERVAL 12 HOUR)")]);
        }
    }

    public function down(): void
    {
        // Narrowing back to DATE drops the time component; the dates themselves
        // survive, so this is lossless for anything the application reads.
        Schema::table('subscriptions', function ($table) {
            foreach ($this->columns as $column) {
                $table->date($column)->nullable()->change();
            }
        });
    }
};
