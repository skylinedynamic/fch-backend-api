<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when billing was paused because the subscriber stopped being Active.
 *
 * Without it, a suspended partner's expected_end_date simply froze in the past and
 * the cron bounced off the status guard every run. On reactivation the rollover
 * logic then met a months-old due date and generated one catch-up invoice per cron
 * run — twice a day until it caught up — auto-charging each one off-session.
 *
 * With this column the cron can tell "paused" from "overdue" and skip the cycles
 * that elapsed while the service was switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('billing_paused_at')
                ->nullable()
                ->after('expected_end_date')
                ->comment('Set while the subscriber is not Active; cleared on resume.');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('billing_paused_at');
        });
    }
};
