<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_id')->unique()->nullable();
            $table->unsignedBigInteger('partner_id');
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('email')->nullable();
            $table->string('country')->nullable();
            $table->decimal('payment_amount', 10, 2)->nullable();
            $table->date('payment_date')->nullable();
            $table->string('status')->default('Pending')->nullable();
            $table->string('cycle')->nullable(); // Annual, Monthly, Quarterly, One Time
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
