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

        $programs = [
            [
                'name' => 'Foundation 100 Play™',
                'sub_title' => 'Build your foundation with these starter cards',
                'target_exposure_min' => 5000,
                'target_exposure_max' => 25000,
                'minimum_fico_requirement' => 680,
                'maximum_utilization_threshold' => 30,
                'difficulty_modifier' => 5,
                'est_timeline_min' => 5,
                'est_timeline_max' => 14,
                'allow_recent_late_payment' => true,
                'late_payment_lookback_months' => 12,
            ],
            [
                'name' => 'Startup Launch Play™',
                'sub_title' => 'Launch your business credit profile',
                'target_exposure_min' => 10000,
                'target_exposure_max' => 50000,
                'minimum_fico_requirement' => 680,
                'maximum_utilization_threshold' => 30,
                'difficulty_modifier' => -5,
                'est_timeline_min' => 7,
                'est_timeline_max' => 21,
                'allow_recent_late_payment' => true,
                'late_payment_lookback_months' => 12,
            ],
            [
                'name' => 'Zero-Cost Capital Play™',
                'sub_title' => 'Access capital without interest costs',
                'target_exposure_min' => 15000,
                'target_exposure_max' => 75000,
                'minimum_fico_requirement' => 720,
                'maximum_utilization_threshold' => 20,
                'difficulty_modifier' => 0,
                'est_timeline_min' => 10,
                'est_timeline_max' => 30,
                'allow_recent_late_payment' => true,
                'late_payment_lookback_months' => 12,
            ],
            [
                'name' => 'Relationship Banking Play™',
                'sub_title' => 'Leverage banking relationships for growth',
                'target_exposure_min' => 25000,
                'target_exposure_max' => 100000,
                'minimum_fico_requirement' => 700,
                'maximum_utilization_threshold' => 25,
                'difficulty_modifier' => 3,
                'est_timeline_min' => 8,
                'est_timeline_max' => 28,
                'allow_recent_late_payment' => true,
                'late_payment_lookback_months' => 12,
            ],
            [
                'name' => 'Investor Liquidity Play™',
                'sub_title' => 'Unlock investor-level liquidity',
                'target_exposure_min' => 50000,
                'target_exposure_max' => 200000,
                'minimum_fico_requirement' => 740,
                'maximum_utilization_threshold' => 15,
                'difficulty_modifier' => -3,
                'est_timeline_min' => 12,
                'est_timeline_max' => 35,
                'allow_recent_late_payment' => true,
                'late_payment_lookback_months' => 12,
            ],
            [
                'name' => 'Foundation-to-Expansion Play™',
                'sub_title' => 'Scale from foundation to expansion',
                'target_exposure_min' => 30000,
                'target_exposure_max' => 150000,
                'minimum_fico_requirement' => 720,
                'maximum_utilization_threshold' => 20,
                'difficulty_modifier' => -5,
                'est_timeline_min' => 10,
                'est_timeline_max' => 30,
                'allow_recent_late_payment' => true,
                'late_payment_lookback_months' => 12,
            ],
            [
                'name' => 'Executive 250 Play™',
                'sub_title' => 'Executive-level credit building',
                'target_exposure_min' => 50000,
                'target_exposure_max' => 250000,
                'minimum_fico_requirement' => 760,
                'maximum_utilization_threshold' => 10,
                'difficulty_modifier' => -10,
                'est_timeline_min' => 14,
                'est_timeline_max' => 40,
                'allow_recent_late_payment' => true,
                'late_payment_lookback_months' => 12,
            ],
            [
                'name' => 'Business Acquisition Play™',
                'sub_title' => 'Finance your next business acquisition',
                'target_exposure_min' => 100000,
                'target_exposure_max' => 500000,
                'minimum_fico_requirement' => 780,
                'maximum_utilization_threshold' => 10,
                'difficulty_modifier' => -12,
                'est_timeline_min' => 14,
                'est_timeline_max' => 45,
                'allow_recent_late_payment' => true,
                'late_payment_lookback_months' => 12,
            ],
            [
                'name' => 'Elite 500 Play™',
                'sub_title' => 'Premium elite credit building',
                'target_exposure_min' => 100000,
                'target_exposure_max' => 500000,
                'minimum_fico_requirement' => 800,
                'maximum_utilization_threshold' => 5,
                'difficulty_modifier' => -20,
                'est_timeline_min' => 14,
                'est_timeline_max' => 45,
                'allow_recent_late_payment' => true,
                'late_payment_lookback_months' => 12,
            ],
            [
                'name' => 'Recovery & Rebuild Play™',
                'sub_title' => 'Rebuild your business credit profile',
                'target_exposure_min' => 3000,
                'target_exposure_max' => 15000,
                'minimum_fico_requirement' => 600,
                'maximum_utilization_threshold' => 40,
                'difficulty_modifier' => -8,
                'est_timeline_min' => 3,
                'est_timeline_max' => 10,
                'allow_recent_late_payment' => true,
                'late_payment_lookback_months' => 12,
            ],
        ];

        foreach ($programs as $program) {
            $capitalAccess = CapitalAccess::create([
                'name'                        => $program['name'],
                'sub_title'                   => $program['sub_title'],
                'partner_id'                  => $partnerId,
                'target_exposure_min'         => $program['target_exposure_min'],
                'target_exposure_max'         => $program['target_exposure_max'],
                'minimum_fico_requirement'    => $program['minimum_fico_requirement'],
                'maximum_utilization_threshold' => $program['maximum_utilization_threshold'],
                'difficulty_modifier'         => $program['difficulty_modifier'],
                'allow_recent_late_payment'   => $program['allow_recent_late_payment'],
                'late_payment_lookback_months' => $program['late_payment_lookback_months'],
                'est_timeline_min'            => $program['est_timeline_min'],
                'est_timeline_max'            => $program['est_timeline_max'],
                'what_you_will_get'           => json_encode([
                    'Step-by-step card sequence',
                    'Estimated approval odds for each card',
                    'Timeline & milestone tracking',
                ]),
                'pre_flight_checklist' => json_encode([
                    'Business Information',
                    'EIN Verified',
                    'Personal Credit Pulled',
                    'Business Bank Account',
                ]),
                'status' => CapitalAccess::STATUS_ACTIVE,
            ]);

            $syncData = [];
            $sortingOrder = 1;
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
