<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Downloads used to save the password a PDF or ZIP was protected with, in
 * plain text, in download_histories.details (BUG-094). New downloads no longer
 * do; this removes the ones already stored. Whether a password was used stays
 * in `action` (downloaded_with_password).
 *
 * Can be run again: rows without a password are left alone. There is no way
 * back - the passwords are not kept anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('download_histories')
            ->whereNotNull('details')
            ->where('details', 'like', '%"password"%')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $details = json_decode((string) $row->details, true);

                    if (!is_array($details) || !array_key_exists('password', $details)) {
                        continue;
                    }

                    unset($details['password']);

                    DB::table('download_histories')
                        ->where('id', $row->id)
                        ->update(['details' => json_encode($details)]);
                }
            });
    }

    public function down(): void
    {
        // The removed passwords cannot be restored.
    }
};
