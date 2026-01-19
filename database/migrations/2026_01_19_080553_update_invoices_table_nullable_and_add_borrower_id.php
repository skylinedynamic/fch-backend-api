<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('borrower_id')->nullable()->constrained('borrowers')->after('partner_id');
            $table->unsignedBigInteger('partner_id')->nullable()->change();
            $table->string('customer_name')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->string('country')->nullable()->change();
            $table->decimal('payment_amount', 10, 2)->nullable()->change();
            $table->date('payment_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['borrower_id']);
            $table->dropColumn('borrower_id');
            // Reverting nullability is complex without knowing previous state exactly, 
            // but for safety we'll just leave them nullable or restore to non-nullable if we can.
            // Since this is a migration, usually we'd restore to original state.
            $table->unsignedBigInteger('partner_id')->nullable(false)->change();
            $table->string('customer_name')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
            $table->string('country')->nullable(false)->change();
            $table->decimal('payment_amount', 10, 2)->nullable(false)->change();
            $table->date('payment_date')->nullable(false)->change();
        });
    }
};
