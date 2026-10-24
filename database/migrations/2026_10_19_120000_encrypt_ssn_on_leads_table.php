<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * leads.ssn_last_4 (the last four digits, plain text) -> leads.ssn_encrypted,
 * which holds the full SSN encrypted, the same way borrowers.ssn_encrypted does
 * (2026_09_06_120000). The last four already on file move across encrypted;
 * the Lead model's ssn accessor reads them back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->text('ssn_encrypted')->nullable()->after('ssn_last_4');
        });

        DB::table('leads')
            ->whereNotNull('ssn_last_4')
            ->where('ssn_last_4', '!=', '')
            ->orderBy('id')
            ->select(['id', 'ssn_last_4'])
            ->chunkById(500, function ($leads) {
                foreach ($leads as $lead) {
                    DB::table('leads')->where('id', $lead->id)->update([
                        'ssn_encrypted' => Crypt::encryptString($lead->ssn_last_4),
                    ]);
                }
            });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('ssn_last_4');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('ssn_last_4', 4)->nullable()->after('ssn_encrypted');
        });

        DB::table('leads')
            ->whereNotNull('ssn_encrypted')
            ->orderBy('id')
            ->select(['id', 'ssn_encrypted'])
            ->chunkById(500, function ($leads) {
                foreach ($leads as $lead) {
                    try {
                        $digits = Crypt::decryptString($lead->ssn_encrypted);
                    } catch (\Throwable) {
                        continue;
                    }

                    DB::table('leads')->where('id', $lead->id)->update(['ssn_last_4' => substr($digits, -4)]);
                }
            });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('ssn_encrypted');
        });
    }
};
