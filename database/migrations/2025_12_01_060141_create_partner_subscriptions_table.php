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
        Schema::create('partner_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->onDelete('cascade');
            $table->string('partner_url')->nullable();
            $table->string('billing_term')->nullable();
            $table->date('first_reg_date')->nullable();
            $table->decimal('first_reg_amount', 10, 2)->nullable();
            $table->decimal('first_payment_amount', 10, 2)->nullable();
            $table->decimal('first_reg_amount_2', 10, 2)->nullable();
            $table->decimal('recurring_amount', 10, 2)->nullable();
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->date('subscription_start_date')->nullable();
            $table->date('subscription_end_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('termination_date')->nullable();
            $table->string('status')->default('active');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partner_subscriptions');
    }
};
