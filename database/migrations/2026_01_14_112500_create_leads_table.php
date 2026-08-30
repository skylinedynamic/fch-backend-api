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
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('lead_id')->nullable()->index();
            $table->foreignId('partner_id')->nullable()->constrained('partners')->onDelete('set null');
            $table->string('tasks_no')->nullable();
            $table->string('probability')->nullable();
            $table->string('lead_type')->default('Borrower')->index();
            $table->string('status')->nullable()->index();
            $table->foreignId('salesperson_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('company_name')->nullable();
            $table->string('domain')->nullable();
            $table->string('lead_source')->nullable();
            $table->string('employees')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('phone_number')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('legal_business_name')->nullable();
            $table->string('business_ein')->nullable();
            $table->string('state_file_number')->nullable();
            $table->string('duns_number')->nullable();
            $table->text('business_address')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip_code')->nullable();
            $table->string('business_phone')->nullable();
            $table->string('entity_type')->nullable();
            $table->string('industry_type')->nullable();
            $table->date('business_start_date')->nullable();
            $table->string('ssn_last_4', 4)->nullable();
            $table->text('requirement_details')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->string('convert_to')->nullable()->index();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
