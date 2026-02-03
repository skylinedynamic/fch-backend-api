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
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('borrower_id')->nullable();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->unsignedBigInteger('funding_application_id')->nullable();
            
            $table->decimal('total_commission', 8, 2)->nullable();
            $table->decimal('admin_commission', 8, 2)->nullable();
            $table->decimal('partner_commission', 8, 2)->nullable();
            
            $table->decimal('total_commission_amount', 15, 2)->nullable();
            $table->decimal('admin_commission_amount', 15, 2)->nullable();
            $table->decimal('partner_commission_amount', 15, 2)->nullable();
            
            $table->string('commission_status')->nullable();
            $table->string('payment_status')->nullable();
            
            $table->string('currency')->default('USD')->nullable();
            $table->string('currency_symbol')->default('$')->nullable();
            $table->decimal('conversion_rate', 10, 2)->default(1.00)->nullable();

            $table->mediumText('note')->nullable();
            
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
