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
        if (!Schema::hasColumn('borrower_task_verification_requests', 'microbilt_response')) {
            Schema::table('borrower_task_verification_requests', function (Blueprint $table) {
                $table->json('microbilt_response')->nullable()->after('note');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('borrower_task_verification_requests', 'microbilt_response')) {
            Schema::table('borrower_task_verification_requests', function (Blueprint $table) {
                $table->dropColumn('microbilt_response');
            });
        }
    }
};
