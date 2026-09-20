<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make the referral tracking fields reportable.
 *
 * `sales_rep` was free text, so it could never be tied back to a real user or
 * used to auto-assign the borrower on conversion. It is replaced by a foreign
 * key to the owning partner's users; the old column stays for submissions that
 * already hold a typed name.
 *
 * `referral_source` becomes a controlled list, with the free-text tail moved to
 * its own column so the list value stays aggregatable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embedded_form_submissions', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_rep_user_id')
                ->nullable()
                ->after('sales_rep')
                ->comment('Partner user selected as the sales rep on the public form');

            $table->string('referral_source_other')
                ->nullable()
                ->after('referral_source')
                ->comment('Free text captured when referral_source is "Other"');

            $table->foreign('sales_rep_user_id', 'efs_sales_rep_user_foreign')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('embedded_form_submissions', function (Blueprint $table) {
            $table->dropForeign('efs_sales_rep_user_foreign');
            $table->dropColumn(['sales_rep_user_id', 'referral_source_other']);
        });
    }
};
