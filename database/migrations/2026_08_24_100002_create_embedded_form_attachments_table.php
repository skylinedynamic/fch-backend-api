<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embedded_form_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('submission_id');
            $table->unsignedBigInteger('partner_id');
            $table->string('field_name', 100)->comment('e.g. paystub_document, credit_report_document');
            $table->string('original_name', 255);
            $table->string('file_path', 500);
            $table->unsignedBigInteger('file_size')->nullable()->comment('File size in bytes');
            $table->string('mime_type', 100)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('submission_id')->references('id')->on('embedded_form_submissions')->onDelete('cascade');
            $table->foreign('partner_id')->references('id')->on('partners')->onDelete('cascade');
            $table->index(['submission_id', 'field_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embedded_form_attachments');
    }
};
