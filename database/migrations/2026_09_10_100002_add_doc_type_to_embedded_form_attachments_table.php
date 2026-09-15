<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attach uploads to a real document type.
 *
 * The embed form used to offer two hard coded buckets (paystub_document,
 * credit_report_document) recorded only as a `field_name` string. The funding
 * application files everything against `borrower_doc_types`, so the embed form
 * now does too - that is what lets a converted submission drop its files
 * straight into funding_application_documents under the right category.
 *
 * field_name is kept: it still holds the bucket a file arrived under, which is
 * how pre-existing rows stay readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embedded_form_attachments', function (Blueprint $table) {
            $table->foreignId('borrower_doc_type_id')
                ->nullable()
                ->after('field_name')
                ->constrained('borrower_doc_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('embedded_form_attachments', function (Blueprint $table) {
            $table->dropForeign(['borrower_doc_type_id']);
            $table->dropColumn('borrower_doc_type_id');
        });
    }
};
