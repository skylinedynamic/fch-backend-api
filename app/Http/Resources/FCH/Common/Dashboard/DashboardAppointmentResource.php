<?php

namespace App\Http\Resources\FCH\Common\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardAppointmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'status' => $this->status,
            // Stored in UTC; read in the viewer's zone (X-Timezone) - BUG-080.
            'appointment_date' => \App\Support\Appointment\AppointmentTime::forViewer($this->appointment_date),
            'meeting_type' => $this->meeting_type,
            'formatted_appointment_date' => \App\Support\Appointment\AppointmentTime::forViewer($this->appointment_date, null, 'M d, Y'),
            'scheduled_by' => $this->whenLoaded('scheduledBy', function () {
                return [
                    'id' => $this->scheduledBy->id,
                    'name' => $this->scheduledBy->name,
                ];
            }),
            'assigned_for' => $this->whenLoaded('assignedFor', function () {
                return [
                    'id' => $this->assignedFor->id,
                    'name' => $this->assignedFor->name,
                ];
            }),
        ];
    }
}
