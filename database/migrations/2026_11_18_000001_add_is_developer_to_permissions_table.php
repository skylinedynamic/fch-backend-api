<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the permissions only the reserved Developer role may hold (the
 * Business Insights group): only a Developer sees them in the permission
 * picker, and no other role or user can be given them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->boolean('is_developer')->default(false)->after('is_certified')
                ->comment('1 = only the Developer role may hold it');
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn('is_developer');
        });
    }
};
