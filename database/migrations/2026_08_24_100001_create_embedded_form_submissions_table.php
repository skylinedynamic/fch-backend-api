<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embedded_form_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('submission_uid', 36)->unique()->comment('UUID reference for this submission');
            $table->unsignedBigInteger('partner_id');
            $table->unsignedBigInteger('form_config_id')->nullable();

            // Personal Information
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 255);
            $table->string('phone', 50)->nullable();
            $table->date('birthday')->nullable();
            $table->text('ssn_encrypted')->nullable()->comment('Encrypted SSN');
            $table->unsignedSmallInteger('credit_score')->nullable();
            $table->decimal('desired_funding_amount', 15, 2)->nullable();

            // Living Information
            $table->string('home_address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->decimal('monthly_housing_payment', 12, 2)->nullable();
            $table->string('housing_status', 50)->nullable()->comment('Rent / Own / Other');

            // Employer Information
            $table->string('employer_name', 255)->nullable();
            $table->string('employer_phone', 50)->nullable();
            $table->string('employer_address', 255)->nullable();
            $table->string('occupation', 100)->nullable();
            $table->decimal('annual_income', 15, 2)->nullable();

            // Referrals & Tracking
            $table->string('sales_rep', 100)->nullable();
            $table->string('referral_source', 255)->nullable();
            $table->string('status', 20)->default('new')->comment('new, reviewing, converted, rejected');
            $table->string('ip_address', 45)->nullable();
            $table->string('origin_url', 500)->nullable();
            $table->unsignedBigInteger('converted_lead_id')->nullable();
            $table->timestamps();

            $table->foreign('partner_id')->references('id')->on('partners')->onDelete('cascade');
            $table->foreign('form_config_id')->references('id')->on('embedded_form_configs')->onDelete('set null');
            $table->index(['partner_id', 'status']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embedded_form_submissions');
    }
};
