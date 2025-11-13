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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->nullable()->constrained('partners');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_number_1')->nullable();
            $table->string('phone_number_2')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable();
            $table->string('department')->nullable();
            $table->string('designation')->nullable();
            $table->string('job_status')->nullable();
            $table->string('joining_date')->nullable();
            $table->string('identification_number')->nullable();
            $table->string('blood_group')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('emergency_phone')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('no_of_children')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->string('address')->nullable();
            $table->string('ssn')->nullable();
            $table->string('image')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Indexes for frequent lookups
            $table->index('first_name');
            $table->index('last_name');
            $table->index('email');
            $table->index('phone_number_1');
            $table->index('job_status');
            $table->index('department');
            $table->index('designation');
            $table->index('partner_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};