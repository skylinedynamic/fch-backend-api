<?php

use Database\Seeders\BorrowerDocTypesSeeder;
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
        Schema::create('borrower_doc_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->nullable();
            $table->integer('sort_order')->default(1);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['name']);
            $table->index(['sort_order']);
        });
    
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('borrower_doc_type_id')->nullable()->constrained('borrower_doc_types')->nullOnDelete();
            $table->string('name', 255)->nullable();
            $table->string('path', 2048);
            $table->string('disk', 50)->default('public');
            $table->string('original_name')->nullable();
            $table->string('extension', 20)->nullable();
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->softDeletes();
            $table->timestamps();
            
            $table->index(['user_id']);
            $table->index(['borrower_doc_type_id']);
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrower_doc_types');
        Schema::dropIfExists('documents');
    }
};
