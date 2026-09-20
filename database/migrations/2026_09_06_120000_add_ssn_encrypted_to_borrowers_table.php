<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renames borrowers.last_4_ssn -> borrowers.ssn_encrypted, which now holds the
 * full 9-digit SSN encrypted at rest (Crypt::encryptString), mirroring the
 * pattern already used for embedded_form_submissions.ssn_encrypted.
 *
 * A straight Schema::rename + type change isn't reliable across DB drivers in
 * one step, so this goes: add the new column -> encrypt each existing
 * (plaintext, last-4-only) value into it -> drop the old column. The
 * Borrower model's ssn/last_4_ssn accessors and mutators keep every existing
 * ->last_4_ssn caller working against the new column transparently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->text('ssn_encrypted')->nullable()->after('last_4_ssn');
        });

        DB::table('borrowers')
            ->whereNotNull('last_4_ssn')
            ->where('last_4_ssn', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($borrowers) {
                foreach ($borrowers as $borrower) {
                    DB::table('borrowers')->where('id', $borrower->id)->update([
                        'ssn_encrypted' => Crypt::encryptString($borrower->last_4_ssn),
                    ]);
                }
            });

        Schema::table('borrowers', function (Blueprint $table) {
            $table->dropColumn('last_4_ssn');
        });
    }

    public function down(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->string('last_4_ssn')->nullable()->after('ssn_encrypted');
        });

        DB::table('borrowers')
            ->whereNotNull('ssn_encrypted')
            ->orderBy('id')
            ->chunkById(200, function ($borrowers) {
                foreach ($borrowers as $borrower) {
                    try {
                        $decrypted = Crypt::decryptString($borrower->ssn_encrypted);
                    } catch (\Throwable $e) {
                        continue;
                    }
                    DB::table('borrowers')->where('id', $borrower->id)->update([
                        'last_4_ssn' => substr($decrypted, -4),
                    ]);
                }
            });

        Schema::table('borrowers', function (Blueprint $table) {
            $table->dropColumn('ssn_encrypted');
        });
    }
};
