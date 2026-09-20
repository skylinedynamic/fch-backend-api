<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backfills columns pre_qualification_requests is read and written with but that
 * no migration ever created.
 *
 * They exist on developer databases — added by hand while the pre-qualification
 * work was in progress — so the code has always worked locally while a freshly
 * migrated environment ends up without them. The model lists both in $fillable
 * and PreQualificationService writes them on every create and update, so their
 * absence breaks pre-qualification on any clean deploy.
 *
 * Guarded with hasColumn so this is a no-op where they are already present.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_qualification_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('pre_qualification_requests', 'business_start_date')) {
                $table->date('business_start_date')->nullable()->after('business_structure');
            }

            if (!Schema::hasColumn('pre_qualification_requests', 'credit_score_source')) {
                $table->string('credit_score_source')->nullable()->after('estimated_business_credit_score');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pre_qualification_requests', function (Blueprint $table) {
            foreach (['business_start_date', 'credit_score_source'] as $column) {
                if (Schema::hasColumn('pre_qualification_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
