<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who paid, approved or rejected (BUG-202).
 *
 * payments: paid_by - the user who paid or tried to pay (succeeded, failed or
 *           waiting); approved_by / rejected_by - who decided a manual payment.
 * invoices: paid_by and approved_by - set once the invoice is paid. A rejection
 *           stays on the payment; the invoice just returns to Unpaid.
 *
 * Automatic charges (cron, auto-charge) have no user, so these stay null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('paid_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('paid_by')->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('paid_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('paid_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('paid_by');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('paid_by');
        });
    }
};
