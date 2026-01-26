<?php

namespace Database\Seeders;

use App\Models\Borrower\BorrowerSignupId;
use App\Models\Partner\Partner;
use Illuminate\Database\Seeder;

class SignupIdSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $partners = Partner::all();

        foreach ($partners as $partner) {
            for ($i = 10000; $i < 10100; $i++) {
                BorrowerSignupId::updateOrCreate([
                    'signup_id' => $partner->id . '-' . $i,
                ], [
                    'partner_id' => $partner->id,
                ]);
            }
        }
    }
}
