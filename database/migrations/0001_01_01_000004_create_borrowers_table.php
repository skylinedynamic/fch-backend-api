<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('borrowers', function (Blueprint $table) {
            $table->id();
            $table->string('signup_id')->nullable()->unique();
            $table->foreignId('partner_id')->nullable()->constrained('partners');
            //personal info
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone_number')->nullable()->index();
            $table->string('country')->nullable();
            $table->string('country_code')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('home_address')->nullable();
            $table->string('home_phone')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('last_4_ssn')->nullable();

            //business info
            $table->string('business_name')->nullable()->index();
            $table->string('business_ein')->nullable()->index();
            $table->string('business_phone')->nullable()->index();
            $table->string('business_address')->nullable();
            $table->string('business_country')->nullable();
            $table->string('business_country_code')->nullable();
            $table->string('business_city')->nullable();
            $table->string('business_state')->nullable();
            $table->string('business_postal_code')->nullable();
            $table->string('business_email')->nullable()->index();
            $table->string('business_website')->nullable();
            $table->string('business_entity_type')->nullable();
            $table->string('business_industry_type')->nullable();
            $table->longText('business_description')->nullable();
            $table->string('business_logo')->nullable();
            $table->string('business_registration_number')->nullable();
            $table->string('tax_identification_number')->nullable();
            $table->string('duns_number')->nullable();
            $table->string('state_file_number')->nullable();
            $table->date('business_start_date')->nullable();

            //financial info
            $table->string('currency')->nullable();
            $table->string('years_in_business')->nullable();
            $table->decimal('monthly_revenue', 23, 2)->nullable();
            $table->decimal('annual_revenue', 23, 2)->nullable();

            $table->enum('status', ['OnBoarding', 'Active', 'Suspended', 'Closed'])->default('OnBoarding')->nullable();
            $table->string('enrollment_type')->nullable();
            $table->foreignId('assign_id')->nullable()->constrained('users');
            $table->json('additional_data')->nullable();
            $table->longText('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrowers');
    }
};
