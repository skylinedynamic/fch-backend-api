<?php

namespace App\Rules;

use App\Services\FCH\Common\Appointment\AppointmentService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An appointment's entity (entity_type + entity_id) must be its attendee's
 * own record - both portals send the picked user's entity. Without this a
 * meeting could be tied to another partner's borrower (findings O20).
 *
 * Checked only when both an entity type and an attendee are given; a missing
 * attendee is left to assigned_for's own rules.
 */
class AttendeesEntity implements ValidationRule
{
    public function __construct(private ?string $entityType, private mixed $attendee)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || empty($this->entityType) || !is_numeric($this->attendee)) {
            return;
        }

        if (!app(AppointmentService::class)->entityIsAttendees($this->entityType, $value, (int) $this->attendee)) {
            $fail(__('appointment.entity_not_attendees'));
        }
    }
}
