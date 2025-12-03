<?php

namespace Database\Seeders;

use App\Models\Partner\Partner;
use App\Models\SupportTicket\SupportTicket;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SupportTicketSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $partners = Partner::all();

        if ($partners->isEmpty()) {
            return;
        }

        foreach ($partners as $partner) {
            // Create 3-8 tickets for each partner
            $count = rand(3, 8);

            for ($i = 0; $i < $count; $i++) {
                SupportTicket::create([
                    'partner_id' => $partner->id,
                    'title' => 'Issue with '.Str::random(5),
                    'priority' => $this->getRandomPriority(),
                    'client_name' => 'Client '.Str::random(4),
                    'assigned_to' => Str::random(3),
                    'due_date' => Carbon::now()->addDays(rand(1, 14)),
                    'sla' => rand(24, 72).' Hours',
                ]);
            }
        }
    }

    private function getRandomPriority()
    {
        $priorities = ['low', 'medium', 'high'];

        return $priorities[array_rand($priorities)];
    }
}
