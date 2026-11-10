<?php

namespace App\Http\Resources\FCH\Appointment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Support\Appointment\AppointmentTime;

use App\Models\Appointment\Appointment;
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
            // Stored in UTC; read in the viewer's zone (X-Timezone) - BUG-080.
            'appointment_date' => AppointmentTime::forViewer($this->appointment_date),
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
                'image' => (function($user) {
                    if (!$user || !$user->entity) return null;
                    $entity = $user->entity;
                    $path = null;
                    if ($entity instanceof Partner) {
                        $path = ($entity->partner_image) ? encodeUrl($entity->partner_image) : null;
                    } elseif ($entity instanceof Employee || $entity instanceof Borrower) {
                        $path = ($entity->image) ? encodeUrl($entity->image) : null;
                    }
                    return $path ? encodeUrl($path) : null;
                })($this->assignedFor),
            ] : null,
            'microsoft_event_id' => $this->microsoft_event_id,
            // A Calendly meeting: its time is changed with reschedule_url, and
            // invitee_* name who booked when they are not an FCH user.
            'source' => $this->source ?? Appointment::SOURCE_FCH,
            'is_calendly' => $this->resource->isFromCalendly(),
            'join_url' => $this->join_url,
            'cancel_url' => $this->cancel_url,
            'reschedule_url' => $this->reschedule_url,
            'invitee_name' => $this->invitee_name,
            'invitee_email' => $this->invitee_email,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
