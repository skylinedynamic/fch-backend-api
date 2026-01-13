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
        Schema::create('funding_application_co_borrowers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funding_application_id')->constrained()->onDelete('cascade');
            
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('title_role')->nullable();
            $table->decimal('ownership_percentage', 8, 2)->nullable();
            $table->string('email')->nullable();
            $table->string('mobile_phone')->nullable();
            $table->string('home_phone')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('ssn')->nullable();
            $table->string('street_address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip_code')->nullable();
            $table->string('drivers_license_number')->nullable();
            $table->string('drivers_license_state')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('funding_application_co_borrowers');
    }
};
