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
        Schema::table('funding_sequencings', function (Blueprint $table) {
            if (!Schema::hasColumn('funding_sequencings', 'require_completed_application')) {
                $table->boolean('require_completed_application')->default(false)->after('allowed_documents');
            }
            if (!Schema::hasColumn('funding_sequencings', 'require_documents_uploaded')) {
                $table->boolean('require_documents_uploaded')->default(false)->after('require_completed_application');
            }
            if (!Schema::hasColumn('funding_sequencings', 'manual_review_allowed')) {
                $table->boolean('manual_review_allowed')->default(false)->after('require_documents_uploaded');
            }
            if (!Schema::hasColumn('funding_sequencings', 'exception_allowed')) {
                $table->boolean('exception_allowed')->default(false)->after('manual_review_allowed');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('funding_sequencings', function (Blueprint $table) {
            $table->dropColumn([
                'require_completed_application',
                'require_documents_uploaded',
                'manual_review_allowed',
                'exception_allowed'
            ]);
        });
    }
};
