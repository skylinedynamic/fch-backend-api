<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A partner's commission: its share (%) of the success fee on its clients'
 * funding applications, set by the host on Partner profile › Commissions. The
 * funding application's commission split opens at it. Null until set, which
 * keeps today's default split (admin 100% / partner 0%).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->decimal('commission', 5, 2)->nullable()->after('agreement_required');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('commission');
        });
    }
};
