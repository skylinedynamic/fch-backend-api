<?php

namespace App\Http\Resources\FCH\Appointment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;

class AppointmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appointment_code' => $this->appointment_code,
            'subject' => $this->subject,
            'description' => $this->description,
            'appointment_date' => $this->appointment_date,
            'timezone' => $this->timezone,
            'duration_minutes' => $this->duration_minutes,
            'status' => $this->status,
            'meeting_type' => $this->meeting_type,
            'location' => $this->location,
            'remarks' => $this->remarks,
            'scheduled_by' => $this->scheduledBy ? [
                'id' => $this->scheduledBy->id,
                'name' => $this->scheduledBy->name,
                'email' => $this->scheduledBy->email,
            ] : null,
            'assigned_for' => $this->assignedFor ? [
                'id' => $this->assignedFor->id,
                'name' => $this->assignedFor->name,
                'email' => $this->assignedFor->email,
                'entity_type' => $this->assignedFor->entity_name,
                'entity_id' => $this->assignedFor->entity_id,
                'image' => $this->assignedFor->image,
            ] : null,
            'microsoft_event_id' => $this->microsoft_event_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
