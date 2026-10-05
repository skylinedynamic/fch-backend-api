<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An employee's whole name now lives in first_name; last_name is a legacy
 * column that's no longer read or written. Existing surnames are folded into
 * first_name (so "Brianna Lopez" doesn't shrink to "Brianna") and last_name is
 * then cleared. The column itself is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('employees')
            ->whereNotNull('last_name')
            ->orderBy('id')
            ->chunkById(200, function ($employees) {
                foreach ($employees as $employee) {
                    $first = trim((string) $employee->first_name);
                    $last = trim((string) $employee->last_name);

                    // Skip the merge when the surname is empty or already part
                    // of first_name, so a re-run can't double it up.
                    $name = ($last === '' || $this->endsWithSurname($first, $last))
                        ? $first
                        : trim("{$first} {$last}");

                    DB::table('employees')
                        ->where('id', $employee->id)
                        ->update(['first_name' => $name, 'last_name' => null]);
                }
            });
    }

    /**
     * Irreversible: once last_name is cleared there's no record of where the
     * surname began ("Superman Employee SS" could have been "Superman" +
     * "Employee SS"), and guessing would corrupt names. Nothing reads
     * last_name any more, so there's nothing to restore.
     */
    public function down(): void
    {
        //
    }

    private function endsWithSurname(string $first, string $last): bool
    {
        return $first === $last || str_ends_with(mb_strtolower($first), ' ' . mb_strtolower($last));
    }
};
