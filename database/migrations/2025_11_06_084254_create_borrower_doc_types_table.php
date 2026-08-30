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
            // Indexes for common queries
            $table->index('name');
            $table->index('sort_order');
        });
    
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->constrained('borrowers')->cascadeOnDelete();
            $table->foreignId('borrower_doc_type_id')->nullable()->constrained('borrower_doc_types')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('path');
            $table->string('disk')->default('public');
            $table->string('original_name')->nullable();
            $table->string('extension')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Indexes for common queries
            $table->index('borrower_id');
            $table->index('borrower_doc_type_id');
            $table->index('name');
            $table->index('path');
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
