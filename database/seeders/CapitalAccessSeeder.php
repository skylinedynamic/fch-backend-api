<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CreditBuilder\CapitalAccess;
use App\Models\CreditBuilder\CreditAccount;

class CapitalAccessSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Empty the tables
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        \Illuminate\Support\Facades\DB::table('capital_access_sequences')->truncate();
        CapitalAccess::truncate();
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $partnerId = 1;

        // Fetch some credit accounts to attach
        $creditAccounts = CreditAccount::inRandomOrder()->take(10)->get();

        if ($creditAccounts->isEmpty()) {
            $this->command->warn('No CreditAccounts found. Please seed CreditAccounts first.');
            return;
        }

        $faker = \Faker\Factory::create();

        for ($i = 1; $i <= 10; $i++) {
            $minExposure = $faker->randomElement([5000, 10000, 25000, 50000]);
            $maxExposure = $minExposure + $faker->randomElement([10000, 25000, 50000, 100000]);

            $capitalAccess = CapitalAccess::create([
                'name' => $faker->catchPhrase() . ' Funding',
                'sub_title' => $faker->sentence(6),
                'partner_id' => $partnerId,
                'target_exposure_min' => $minExposure,
                'target_exposure_max' => $maxExposure,
                'est_timeline_min' => $faker->numberBetween(3, 14),
                'est_timeline_max' => $faker->numberBetween(15, 45),
                'what_you_will_get' => json_encode([
                    $faker->sentence(4),
                    $faker->sentence(5),
                    $faker->sentence(4)
                ]),
                'pre_flight_checklist' => json_encode([
                    'Business Information',
                    'EIN Verified',
                    'Personal Credit Pulled',
                    'Business Bank Account'
                ]),
                'status' => CapitalAccess::STATUS_ACTIVE,
            ]);

            // Sync credit accounts with demo sequence details
            $syncData = [];
            $sortingOrder = 1;
            
            // Take 2 or 3 cards for each program
            $cardsToAttach = $creditAccounts->random(rand(2, 3));

            foreach ($cardsToAttach as $card) {
                $syncData[$card->id] = [
                    'sorting_order' => $sortingOrder++,
                    'why_this_card' => 'This card offers great rewards and helps build your business credit profile.',
                    'est_limit_min' => rand(1000, 5000),
                    'est_limit_max' => rand(10000, 50000),
                    'hard_pull' => rand(0, 1) ? CapitalAccess::HARD_PULL_YES : CapitalAccess::HARD_PULL_NO,
                    'what_you_need' => json_encode(['Good personal credit', 'Established business entity']),
                ];
            }

            $capitalAccess->creditAccounts()->sync($syncData);
        }
    }
}
