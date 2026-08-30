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
        Schema::create('funding_application_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique()->nullable();
            $table->foreignId('funding_application_id')->constrained('funding_applications')->cascadeOnDelete();
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
            $table->index('funding_application_id');
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
        Schema::dropIfExists('funding_application_documents');
    }
};
