<?php

use App\Models\Company\Company;
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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();            
            $table->string('company_no')->nullable();
            $table->string('company_name');
            $table->string('company_ein')->nullable();
            $table->string('company_email')->nullable();
            $table->string('company_phone')->nullable();
            $table->string('company_website')->nullable();
            $table->string('company_entity_type')->nullable();
            $table->string('company_type')->nullable();
            $table->text('company_description')->nullable();

            $table->string('company_logo')->nullable();
            $table->enum('status', Company::STATUSES)->default(Company::STATUS_PENDING);
            $table->string('company_registration_number')->nullable();
            $table->string('tax_identification_number')->nullable();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->onDelete('set null');

            
            // Company address
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            
            
            $table->json('additional_data')->nullable(); // For flexible data storage
            $table->text('notes')->nullable(); // Internal notes

            $table->string('currency')->nullable();
            $table->integer('years_in_business')->nullable();
            $table->decimal('monthly_revenue', 10, 2)->nullable();
            $table->decimal('annual_revenue', 10, 2)->nullable();
   
            $table->softDeletes();
            $table->timestamps();
            
            // Indexes in separate lines
            $table->index('company_name');
            $table->index('company_email');
            $table->index('city');
            $table->index('state');
            $table->index('country');
            $table->index('status');
            $table->index('assigned_agent_id');
            $table->index('created_at');
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
