<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * created_by / updated_by are used throughout FundingApplicationService and
 * PartnerFundingApplicationService, and already exist on the live database,
 * but no prior migration ever created them - a fresh environment (CI, a new
 * dev machine, the test suite) migrating from scratch ends up without them.
 * Guarded with hasColumn() so this is a no-op on databases that already have
 * the columns from before this migration existed.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('funding_applications', function (Blueprint $table) {
            if (!Schema::hasColumn('funding_applications', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('updated_at');
            }
            if (!Schema::hasColumn('funding_applications', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->dropColumn(['created_by', 'updated_by']);
        });
    }
};
