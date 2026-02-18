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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_paid')->after('phone_number')->default(false)->nullable();
            $table->date('start_date')->after('is_paid')->nullable();
            $table->date('end_date')->after('start_date')->nullable();
            $table->string('payment_status')->after('end_date')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_paid', 'start_date', 'end_date', 'payment_status']);
        });
    }
};
