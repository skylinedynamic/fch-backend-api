<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypt credit_profile_requests.ssn at rest (findings D10).
 *
 * The SSN a credit pull is keyed on was stored in plain text. The model now
 * casts it `encrypted`; this widens the column for the ciphertext and encrypts
 * the rows already there. A value that already decrypts is left alone, so the
 * migration can be re-run safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_profile_requests', function (Blueprint $table) {
            $table->text('ssn')->change();
        });

        DB::table('credit_profile_requests')
            ->whereNotNull('ssn')
            ->where('ssn', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    try {
                        Crypt::decryptString($row->ssn);
                        continue; // already encrypted
                    } catch (DecryptException) {
                        // plain text: encrypt it below
                    }

                    DB::table('credit_profile_requests')
                        ->where('id', $row->id)
                        ->update(['ssn' => Crypt::encryptString($row->ssn)]);
                }
            });
    }

    public function down(): void
    {
        DB::table('credit_profile_requests')
            ->whereNotNull('ssn')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    try {
                        $plain = Crypt::decryptString($row->ssn);
                    } catch (DecryptException) {
                        continue;
                    }

                    DB::table('credit_profile_requests')->where('id', $row->id)->update(['ssn' => $plain]);
                }
            });

        Schema::table('credit_profile_requests', function (Blueprint $table) {
            $table->string('ssn')->change();
        });
    }
};
