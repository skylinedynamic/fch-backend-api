<?php

namespace App\Http\Requests\V1\FCH\Common\Appointment;

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
            'assigned_for' => 'nullable|exists:users,id',
            'remarks' => 'nullable|string',
        ];
    }
}
