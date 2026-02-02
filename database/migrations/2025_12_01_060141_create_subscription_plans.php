<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('partner_type')->nullable();
            $table->string('partner_type_slug')->nullable();
            $table->string('enrollment_type')->nullable();
            $table->string('enrollment_type_slug')->nullable();
            $table->string('entity_type')->nullable()->comment('Partner or Borrower, NULL for User');
            $table->boolean('is_partner_paying')->nullable()->default(false); // only used for borrower payment if true partner is paying for borrower
            $table->decimal('payment_amount', 10, 2)->nullable();
            $table->decimal('total_installments_count', 10, 2)->nullable(); // total installments
            $table->decimal('subscription_period_month', 10, 2)->nullable(); // total subscription period in months
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->decimal('platform_fee', 10, 2)->nullable();
            $table->string('currency')->default('USD')->nullable();
            $table->string('currency_symbol')->default('$')->nullable();
            $table->decimal('conversion_rate', 10, 2)->default(1.00)->nullable();
            $table->boolean('status')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
