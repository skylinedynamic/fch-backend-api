<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Co-borrowers captured by the public embedded form.
 *
 * Mirrors `funding_application_co_borrowers` column for column so the rows can
 * be copied straight across when a submission is converted. SSNs are the one
 * difference: the public form is unauthenticated, so the value is stored
 * encrypted here and only decrypted at conversion time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embedded_form_co_borrowers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')
                ->constrained('embedded_form_submissions')
                ->cascadeOnDelete();

            $table->string('first_name')->nullable()->index();
            $table->string('last_name')->nullable()->index();
            $table->string('title_role')->nullable();
            $table->decimal('ownership_percentage', 8, 2)->nullable();
            $table->string('email')->nullable()->index();
            $table->string('mobile_phone')->nullable();
            $table->string('home_phone')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('ssn_encrypted')->nullable()->comment('Encrypted SSN');
            $table->string('street_address')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip_code', 20)->nullable();
            $table->string('drivers_license_number')->nullable();
            $table->string('drivers_license_state')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embedded_form_co_borrowers');
    }
};
