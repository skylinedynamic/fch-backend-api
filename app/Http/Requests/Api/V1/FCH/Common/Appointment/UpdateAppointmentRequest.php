<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Appointment;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Appointment\Appointment;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
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
            'assigned_for' => 'required|exists:users,id',
            'entity_id' => 'nullable|integer',
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
