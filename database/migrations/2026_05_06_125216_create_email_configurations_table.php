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
        Schema::dropIfExists('email_configurations');
        
        Schema::create('email_configurations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->boolean('enable_custom_configuration')->default(false);
            $table->string('site_name')->nullable();
            $table->string('email_provider')->nullable(); // smtp, aws_ses
            
            // SMTP Settings
            $table->string('smtp_driver')->default('smtp');
            $table->string('smtp_host')->nullable();
            $table->string('smtp_port')->nullable();
            $table->string('smtp_encryption')->nullable();
            $table->string('smtp_username')->nullable();
            $table->string('smtp_password')->nullable();
            
            // AWS SES Settings
            $table->string('aws_access_key')->nullable();
            $table->string('aws_secret_key')->nullable();
            $table->string('aws_region')->nullable();
            
            // Common Settings
            $table->string('from_address')->nullable();
            // $table->string('from_name')->nullable();
            $table->text('bcc_emails')->nullable();
            
            $table->boolean('is_active')->default(false);
            $table->boolean('is_fresh_email')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['partner_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_configurations');
    }
};
