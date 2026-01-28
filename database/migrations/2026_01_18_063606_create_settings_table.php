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
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('payment_mode')->default('sandbox'); // sandbox | production
            $table->string('payment_method')->default('stripe'); // credit_card | bank_transfer | crypto
            $table->string('stripe_key_sandbox')->nullable();
            $table->string('stripe_secret_sandbox')->nullable();
            $table->string('stripe_key_production')->nullable();
            $table->string('stripe_secret_production')->nullable();
            $table->decimal('non_host_enrollment_price', 10, 2)->default(0.00)->nullable();
            $table->boolean('status')->default(true);
            $table->json('configuration')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
