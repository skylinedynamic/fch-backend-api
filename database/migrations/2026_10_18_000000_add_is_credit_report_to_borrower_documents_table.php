<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the iSoftPull report uploaded on Client > Credit Profile. It is kept as
 * a borrower document, but it belongs to the credit profile and stays out of
 * the client's Documents lists.
 *
 * Reports uploaded before are marked too: the one a credit profile points at,
 * and any other the upload stored under its credit_report_<time>.pdf name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('borrower_documents', function (Blueprint $table) {
            $table->boolean('is_credit_report')->default(false)->after('file_path')->index();
        });

        $this->backfill();
    }

    public function backfill(): void
    {
        $linked = DB::table('borrower_credit_profiles')->whereNotNull('document_id')->pluck('document_id');

        DB::table('borrower_documents')
            ->where(function ($query) use ($linked) {
                $query->whereIn('id', $linked)
                    ->orWhere('file_path', 'like', 'credit_report_%.pdf');
            })
            ->update(['is_credit_report' => true]);
    }

    public function down(): void
    {
        Schema::table('borrower_documents', function (Blueprint $table) {
            $table->dropIndex(['is_credit_report']);
            $table->dropColumn('is_credit_report');
        });
    }
};
