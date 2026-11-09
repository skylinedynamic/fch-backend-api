<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this partner / client is asked to sign their agreement. On by
 * default, so nothing changes for anyone until the host excuses someone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->boolean('agreement_required')->default(true)->after('has_sign');
        });

        Schema::table('borrowers', function (Blueprint $table) {
            $table->boolean('agreement_required')->default(true)->after('has_sign');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('agreement_required');
        });

        Schema::table('borrowers', function (Blueprint $table) {
            $table->dropColumn('agreement_required');
        });
    }
};
