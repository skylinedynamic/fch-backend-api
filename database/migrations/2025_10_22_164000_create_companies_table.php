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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            $table->string('company_name');
            $table->string('company_code')->unique()->nullable();
            $table->string('company_email')->nullable();
            $table->string('company_phone')->nullable();
            $table->string('company_website')->nullable();
            $table->text('company_description')->nullable();
            
            // Company address
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            
            // Company branding
            $table->string('company_logo')->nullable();
            $table->enum('status', \App\Models\Company\Company::STATUSES)->default(\App\Models\Company\Company::STATUS_PENDING);
            $table->string('business_registration_number')->nullable();
            $table->string('tax_identification_number')->nullable();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->onDelete('set null');

            $table->json('additional_data')->nullable(); // For flexible data storage
            $table->text('notes')->nullable(); // Internal notes
   
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes in separate lines
            $table->index('user_id');
            $table->index('company_name');
            $table->index('company_email');
            $table->index('city');
            $table->index('state');
            $table->index('country');
            $table->index('status');
            $table->index('assigned_agent_id');
            $table->index('created_at');
            $table->index(['user_id', 'status']);
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
