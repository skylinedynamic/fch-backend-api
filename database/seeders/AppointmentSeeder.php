<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Models\Appointment\Appointment;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AppointmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $partners = Partner::all();
        $users = User::all();

        if ($partners->isEmpty() || $users->isEmpty()) {
            return;
        }

        foreach ($partners as $partner) {
            // Create 5-10 appointments for each partner
            $count = rand(5, 10);
            
            for ($i = 0; $i < $count; $i++) {
                $scheduledBy = $users->random();
                $assignedFor = $users->random();
                
                Appointment::create([
                    'appointment_code' => 'APT-' . strtoupper(Str::random(8)),
                    'scheduled_by' => $scheduledBy->id,
                    'assigned_for' => $assignedFor->id,
                    'subject' => 'Meeting with ' . $partner->company_name,
                    'description' => 'Discussion regarding partnership details and future collaboration.',
                    'appointment_date' => Carbon::now()->addDays(rand(-30, 30))->addHours(rand(9, 17)),
                    'timezone' => 'UTC',
                    'duration_minutes' => rand(30, 120),
                    'status' => $this->getRandomStatus(),
                    'meeting_type' => $this->getRandomMeetingType(),
                    'location' => 'Online via Microsoft Teams',
                    'remarks' => 'Please prepare the monthly report.',
                ]);
            }
        }
    }

    private function getRandomStatus()
    {
        $statuses = [
            Appointment::STATUS_PENDING,
            Appointment::STATUS_CONFIRMED,
            Appointment::STATUS_REJECTED,
            Appointment::STATUS_RESCHEDULED,
            Appointment::STATUS_COMPLETED,
            Appointment::STATUS_CANCELLED,
        ];
        return $statuses[array_rand($statuses)];
    }

    private function getRandomMeetingType()
    {
        $types = [
            Appointment::MEETING_TYPE_INITIAL_CONSULTATION,
            Appointment::MEETING_TYPE_FOLLOWUP_MEETING,
            Appointment::MEETING_TYPE_BUSINESS_REVIEW,
            Appointment::MEETING_TYPE_TECHNICAL_SUPPORT,
            Appointment::MEETING_TYPE_FEEDBACK_SESSION,
            Appointment::MEETING_TYPE_PARTNERSHIP_MEETING,
        ];
        return $types[array_rand($types)];
    }
}
