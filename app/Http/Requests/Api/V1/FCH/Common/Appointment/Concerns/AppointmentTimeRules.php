<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Appointment\Concerns;

use App\Models\Appointment\Appointment;
use App\Support\Appointment\AppointmentTime;
use Carbon\Carbon;
use Illuminate\Validation\Validator;

/**
 * When an appointment may be, for the create and update requests:
 *
 *  - the time is entered in the booker's zone and validated, and saved, in UTC
 *    (BUG-080);
 *  - a time that has already passed is refused - an existing appointment keeps
 *    its own past time when edited (BUG-078);
 *  - a time that overlaps another live appointment of either person on it -
 *    the booker or the attendee - is refused; back-to-back is fine (BUG-079).
 */
trait AppointmentTimeRules
{
    /** Statuses whose appointments hold their slot. */
    private const SLOT_HOLDING_STATUSES = [
        Appointment::STATUS_PENDING,
        Appointment::STATUS_CONFIRMED,
        Appointment::STATUS_RESCHEDULED,
    ];

    /** The zone the time was entered in, once it is known to be valid. */
    private ?string $enteredZone = null;

    /** The appointment being edited; null when booking a new one. */
    abstract protected function appointmentBeingEdited(): ?Appointment;

    /**
     * Reads the zone by its name and turns the entered time into UTC. An
     * unknown zone, or a time that is not a time, is left as sent for the
     * rules to refuse.
     */
    protected function prepareAppointmentTime(): void
    {
        if (!$this->has('timezone')) {
            return;
        }

        $zone = AppointmentTime::zone($this->input('timezone'));
        if ($zone === null) {
            return;
        }

        $this->enteredZone = $zone;
        $merge = ['timezone' => $zone];

        $local = $this->input('appointment_date');
        if (is_string($local) && strtotime($local) !== false) {
            $merge['appointment_date'] = AppointmentTime::toUtc($local, $zone);
        }

        $this->merge($merge);
    }

    protected function timezoneRules(bool $required): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'timezone:all'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || !$this->has('appointment_date') || $this->enteredZone === null) {
                return;
            }

            $editing = $this->appointmentBeingEdited();
            $start = Carbon::parse($this->input('appointment_date'), 'UTC');
            $minutes = (int) ($this->input('duration_minutes') ?? $editing?->duration_minutes ?? 0);
            $end = $start->copy()->addMinutes(max($minutes, 1));

            $timeChanged = !$editing || !$start->equalTo(Carbon::parse($editing->appointment_date, 'UTC'));
            if ($timeChanged && $start->lessThan(now())) {
                $validator->errors()->add('appointment_date', __('appointment.time_in_past'));

                return;
            }

            $clash = $this->clashingAppointment($start, $end, $editing);
            if ($clash) {
                $validator->errors()->add('appointment_date', $this->clashMessage($clash));
            }
        });
    }

    /** The first live appointment of the booker or the attendee that overlaps [$start, $end). */
    private function clashingAppointment(Carbon $start, Carbon $end, ?Appointment $editing): ?Appointment
    {
        $people = array_values(array_filter([
            (int) $this->user()?->id,
            (int) ($this->input('assigned_for') ?? $editing?->assigned_for),
        ]));

        return Appointment::query()
            ->whereIn('status', self::SLOT_HOLDING_STATUSES)
            ->where(fn ($q) => $q->whereIn('scheduled_by', $people)->orWhereIn('assigned_for', $people))
            ->when($editing, fn ($q) => $q->whereKeyNot($editing->getKey()))
            // A wide window in SQL, the exact overlap in PHP - so no
            // database-specific date arithmetic on the duration.
            ->whereBetween('appointment_date', [
                $start->copy()->subDay()->format(AppointmentTime::STORED_FORMAT),
                $end->format(AppointmentTime::STORED_FORMAT),
            ])
            ->with(['scheduledBy', 'assignedFor'])
            ->get()
            ->first(function (Appointment $other) use ($start, $end) {
                $otherStart = Carbon::parse($other->appointment_date, 'UTC');
                $otherEnd = $otherStart->copy()->addMinutes(max((int) $other->duration_minutes, 1));

                return $otherStart->lessThan($end) && $start->lessThan($otherEnd);
            });
    }

    /** Whose time it is, and when, in the zone the booker entered it in. */
    private function clashMessage(Appointment $clash): string
    {
        $from = Carbon::parse($clash->appointment_date, 'UTC')->setTimezone($this->enteredZone);
        $times = [
            'from' => $from->format('g:i A'),
            'to' => $from->copy()->addMinutes(max((int) $clash->duration_minutes, 1))->format('g:i A'),
        ];

        $me = (int) $this->user()?->id;
        if ((int) $clash->scheduled_by === $me || (int) $clash->assigned_for === $me) {
            return __('appointment.time_taken_by_you', $times);
        }

        $attendee = (int) $clash->assigned_for === (int) $this->input('assigned_for') ? $clash->assignedFor : $clash->scheduledBy;

        return __('appointment.time_taken_by', $times + ['name' => $attendee?->name ?? __('appointment.the_attendee')]);
    }
}
