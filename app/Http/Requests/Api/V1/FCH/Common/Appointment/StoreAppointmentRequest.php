<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Appointment;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Appointment\Appointment;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string',
            'appointment_date' => 'required',
            'timezone' => 'required|string',
            'duration_minutes' => 'required|integer|min:1',
            'status' => ['nullable', Rule::in(array_keys(Appointment::STATUSES))],
            'meeting_type' => ['required', Rule::in(array_keys(Appointment::MEETING_TYPES))],
            'location' => 'nullable|string|max:255',
            'assigned_for' => 'required|exists:users,id',
            'entity_id' => 'nullable|integer',
            'entity_type' => 'nullable|string',
            'time_slot_id' => 'nullable|exists:time_slots,id',
        ];
    }

    public function messages(): array
    {
        return [
            'subject.required' => __('appointment.subject_required'),
            'appointment_date.required' => __('appointment.date_required'),
            'appointment_date.date_format' => __('appointment.date_invalid'),
            'duration_minutes.required' => __('appointment.duration_required'),
            'duration_minutes.min' => __('appointment.duration_min'),
            'meeting_type.required' => __('appointment.meeting_type_required'),
            'meeting_type.in' => __('appointment.meeting_type_invalid'),
            'status.in' => __('appointment.status_invalid'),
            'assigned_for.exists' => __('appointment.assigned_for_invalid'),
            'time_slot_id.exists' => __('appointment.time_slot_invalid'),
        ];
    }
}
