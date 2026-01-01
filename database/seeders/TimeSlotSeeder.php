<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\FCH\Common\TimeSlot\TimeSlot;
use Carbon\Carbon;

class TimeSlotSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $start = Carbon::createFromTime(0, 0);
        $end = Carbon::createFromTime(24, 0);

        while ($start->lessThan($end)) {
            $slotEnd = $start->copy()->addHour();
            
            TimeSlot::create([
                'start_time' => $start->format('H:i:s'),
                'end_time'   => $slotEnd->format('H:i:s'),
                'label'      => $start->format('h:i A') . ' - ' . $slotEnd->format('h:i A'),
                'is_active'  => true,
            ]);

            $start->addHour();
        }
    }
}
