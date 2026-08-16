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
        Schema::create('download_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('user_name')->nullable();
            $table->string('user_type')->nullable();
            $table->foreignId('funding_application_id')->nullable()->constrained('funding_applications')->onDelete('cascade');
            $table->string('application_number')->nullable();
            $table->string('application_version')->nullable();
            $table->string('certificate_id')->nullable();
            $table->string('file_type');
            $table->string('action')->nullable();
            $table->string('sha256_hash');
            $table->string('ip_address')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('downloaded_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('download_histories');
    }
};
