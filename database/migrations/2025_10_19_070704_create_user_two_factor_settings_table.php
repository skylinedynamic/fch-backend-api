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
        Schema::create('user_two_factor_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->boolean('enabled')->default(false);
            $table->enum('method', ['totp', 'sms', 'email'])->nullable();
            $table->text('secret')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->json('recovery_codes')->nullable(); 
            $table->string('code', 10)->nullable(); 
            $table->timestamp('code_expires_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['user_id', 'enabled']);
            $table->index('code_expires_at');
            $table->index('locked_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_two_factor_settings');
    }
};
