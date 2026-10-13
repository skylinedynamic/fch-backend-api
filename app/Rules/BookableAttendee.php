<?php

namespace App\Rules;

use App\Services\FCH\Common\Appointment\AppointmentService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;

/**
 * An appointment's attendee (assigned_for) must be someone the signed-in user
 * may book with - a user on their bookable-people list, the same list the
 * booking form offers (findings O20). It used to take any user id on the
 * platform, so a borrower or partner could put a meeting, and its email and
 * bell notification, on another partner's users.
 *
 * $unchanged is the attendee an existing appointment already has: keeping it
 * is always allowed - a partner editing a meeting a borrower booked with it is
 * its own attendee, and never on its own list.
 */
class BookableAttendee implements ValidationRule
{
    public function __construct(private ?int $unchanged = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_numeric($value)) {
            $fail(__('appointment.assigned_for_invalid'));
            return;
        }

        if ($this->unchanged !== null && (int) $value === $this->unchanged) {
            return;
        }

        $caller = Auth::user();

        if (!$caller || !app(AppointmentService::class)->canBookWith($caller, (int) $value)) {
            $fail(__('appointment.assigned_for_not_bookable'));
        }
    }
}
