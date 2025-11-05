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
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('business_no')->nullable();
            $table->string('business_name')->nullable();
            $table->string('business_ein')->nullable();
            $table->string('business_email')->nullable();
            $table->string('business_phone')->nullable();
            $table->string('business_website')->nullable();
            $table->string('business_entity_type')->nullable();
            $table->string('business_type')->nullable();
            $table->longText('business_description')->nullable();
            $table->string('business_logo')->nullable();
            $table->string('status')->nullable();
            $table->string('business_registration_number')->nullable();
            $table->string('tax_identification_number')->nullable();
            $table->foreignId('assign_id')->nullable()->constrained('users');
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->json('additional_data')->nullable();
            $table->string('duns_number')->nullable();
            $table->string('state_file_number')->nullable();
            $table->date('business_start_date')->nullable();
            $table->longText('notes')->nullable();
            $table->string('currency')->nullable();
            $table->integer('years_in_business')->nullable();
            $table->decimal('monthly_revenue', 23, 2)->nullable();
            $table->decimal('annual_revenue', 23, 2)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};