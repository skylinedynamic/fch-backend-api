<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Appointment;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Appointment\Appointment;
use App\Rules\AttendeesEntity;
use App\Rules\BookableAttendee;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    private ?Appointment $appointment = null;

    /**
     * Only the two people on a meeting may change it. Someone else gets the
     * controller's 404 here, before validation - a validation error would
     * confirm the meeting exists.
     */
    protected function prepareForValidation(): void
    {
        $userId = $this->user()?->id;

        $this->appointment = Appointment::where(fn ($q) => $q->where('scheduled_by', $userId)->orWhere('assigned_for', $userId))
            ->findOrFail($this->route('appointment'));
    }

    public function rules(): array
    {
        return [
            'subject' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'appointment_date' => 'sometimes|date_format:Y-m-d H:i:s',
            'timezone' => 'sometimes|string',
            'duration_minutes' => 'sometimes|integer|min:1',
            'status' => ['sometimes', Rule::in(array_keys(Appointment::STATUSES))],
            'meeting_type' => ['sometimes', Rule::in(array_keys(Appointment::MEETING_TYPES))],
            'location' => 'nullable|string|max:255',
            // Someone on the caller's bookable-people list, not any user (O20).
            'assigned_for' => ['required', 'exists:users,id', new BookableAttendee($this->appointment?->assigned_for)],
            'entity_id' => ['nullable', 'integer', new AttendeesEntity($this->input('entity_type'), $this->input('assigned_for'))],
            'entity_type' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'subject.string' => __('appointment.subject_required'),
            'appointment_date.date_format' => __('appointment.date_invalid'),
            'duration_minutes.integer' => __('appointment.duration_required'),
            'duration_minutes.min' => __('appointment.duration_min'),
            'meeting_type.in' => __('appointment.meeting_type_invalid'),
            'status.in' => __('appointment.status_invalid'),
            'assigned_for.exists' => __('appointment.assigned_for_invalid'),
        ];
    }
}
