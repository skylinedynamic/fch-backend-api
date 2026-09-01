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
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->integer('user_seats')->nullable()->after('status');
            $table->decimal('per_seat_amount', 10, 2)->nullable()->after('user_seats');
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['total_free_users', 'total_paid_users']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->integer('total_free_users')->nullable()->after('assign_to');
            $table->integer('total_paid_users')->nullable()->after('total_free_users');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['user_seats', 'per_seat_amount']);
        });
    }
};
