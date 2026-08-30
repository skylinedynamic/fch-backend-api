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
        Schema::table('borrower_documents', function (Blueprint $table) {
            $table->string('status')->default('Pending')->after('file_path');
            $table->text('rejection_reason')->nullable()->after('status');
            $table->string('file_size')->nullable()->after('rejection_reason');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('borrower_documents', function (Blueprint $table) {
            $table->dropColumn(['status', 'rejection_reason', 'file_size', 'updated_by']);
        });
    }
};
