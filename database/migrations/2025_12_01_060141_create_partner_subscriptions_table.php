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
            $table->string('partner_type')->nullable();
            $table->string('enrollment_type')->nullable();
            $table->decimal('monthly_platform_fee', 10, 2)->nullable();
            $table->decimal('first_reg_amount', 10, 2)->nullable();
            $table->date('first_reg_date')->nullable();
            $table->tinyInteger('installment_type')->nullable()->comment('0 = No, 1 = Yes');
            $table->decimal('first_reg_payment', 10, 2)->nullable();
            $table->decimal('first_reg_payment_2', 10, 2)->nullable();
            $table->decimal('total_paid_amount', 10, 2)->nullable();
            $table->date('subscription_start_date')->nullable();
            $table->date('subscription_end_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('termination_date')->nullable();
            $table->string('status')->default('Active');
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
