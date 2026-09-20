<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether billing is paused is derived, not stored.
 *
 * billing_paused_at cached one fact: "this subscriber is not Active". The cron
 * stamped it when it next processed the row, which meant it was wrong in the
 * window between an admin suspending someone and that run — so every reader ended
 * up checking the subscriber's live status anyway, and the column was ORed with
 * that status in three separate places rather than trusted on its own.
 *
 * It also earned its keep under the old billing model, where pausing had to freeze
 * the scheduling date and resuming had to walk it forward past the cycles that
 * elapsed during the pause. That is gone: only one invoice may be open at a time,
 * so however long a pause ran, resuming raises exactly one.
 *
 * What replaces it: Subscription::billingPausedReason() in PHP and the
 * billingBlocked() scope in SQL, both derived from live status every time they are
 * asked, so neither can go stale.
 *
 * NOTE: down() restores the column but not the values. Nothing reads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('subscriptions', 'billing_paused_at')) {
            return;
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('billing_paused_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('subscriptions', 'billing_paused_at')) {
            return;
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('billing_paused_at')->nullable();
        });
    }
};
