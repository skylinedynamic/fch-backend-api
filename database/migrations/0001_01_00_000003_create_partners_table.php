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
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_host')->default(false); // if admin then true
            $table->string('full_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_person_email')->nullable();
            $table->string('contact_person_phone_number')->nullable();
            $table->string('contact_person_relation')->nullable();
            $table->string('partner_type')->nullable();
            $table->string('status')->default('active')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('country')->nullable();
            $table->string('joining_date')->nullable();
            $table->string('company_logo')->nullable();
            $table->string('partner_image')->nullable();
            $table->unsignedBigInteger('assign_to')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Indexes for filtering partners
            $table->index('full_name');
            $table->index('company_name');
            $table->index('contact_person');
            $table->index('partner_type');
            $table->index('is_host');
            $table->index('status');
            $table->index('email');
            $table->index('phone_number');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};