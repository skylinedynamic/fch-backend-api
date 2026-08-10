<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('default_payment_method')->nullable()->after('stripe_customer_id');
        });

        Schema::table('borrowers', function (Blueprint $table) {
            $table->string('default_payment_method')->nullable()->after('stripe_customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('default_payment_method');
        });

        Schema::table('borrowers', function (Blueprint $table) {
            $table->dropColumn('default_payment_method');
        });
    }
};
