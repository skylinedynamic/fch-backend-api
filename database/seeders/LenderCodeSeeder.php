<?php

namespace Database\Seeders;

use App\Models\Lender\Lender;
use Illuminate\Database\Seeder;

/**
 * Gives a Lender Code ("LND-" . 4 random digits . lender id, e.g. LND-93245)
 * to every lender - deleted ones too - that has none, or one in an older
 * format (the first version of the migration gave "LND-5890", without the id).
 * Codes already in the format are kept, so it can be run again safely:
 *
 *   php artisan migrate        (widens the column - 2026_11_11_000002)
 *   php artisan db:seed --class=LenderCodeSeeder
 *
 * A new lender gets its code when it is created (Lender::booted), and the
 * migration that adds the column fills the lenders already saved; this is for
 * any table filled without either, or holding the old format.
 */
class LenderCodeSeeder extends Seeder
{
    public function run(): void
    {
        $given = Lender::fillMissingLenderCodes();

        $this->command?->info($given > 0
            ? "✅ {$given} lender(s) given a Lender Code."
            : '✅ Every lender already has a Lender Code.');
    }
}
