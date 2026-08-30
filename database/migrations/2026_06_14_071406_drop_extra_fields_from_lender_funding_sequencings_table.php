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
        Schema::table('lender_visibilities', function (Blueprint $table) {
            $table->dropColumn([
                'require_completed_application',
                'require_documents_uploaded',
                'allowed_documents',
                'manual_review_allowed',
                'exception_allowed'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lender_visibilities', function (Blueprint $table) {
            $table->boolean('require_completed_application')->default(false);
            $table->boolean('require_documents_uploaded')->default(false);
            $table->json('allowed_documents')->nullable();
            
            $table->boolean('manual_review_allowed')->default(false);
            $table->boolean('exception_allowed')->default(false);
        });
    }
};
