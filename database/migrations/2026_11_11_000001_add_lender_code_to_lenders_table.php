<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every lender's Lender Code: "LND-", four random digits, then the lender's id
 * (lender 5: "LND-93245") - unique because the id is. Made by the system
 * (App\Models\Lender\Lender::lenderCodeFor). Lenders already saved - deleted
 * ones too - are given one here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lenders', function (Blueprint $table) {
            $table->string('lender_code', 32)->nullable()->unique()->after('id');
        });

        DB::table('lenders')->whereNull('lender_code')->orderBy('id')->pluck('id')->each(function (int $id) {
            DB::table('lenders')->where('id', $id)->update([
                'lender_code' => sprintf('LND-%04d%d', random_int(0, 9999), $id),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('lenders', function (Blueprint $table) {
            $table->dropUnique(['lender_code']);
            $table->dropColumn('lender_code');
        });
    }
};
