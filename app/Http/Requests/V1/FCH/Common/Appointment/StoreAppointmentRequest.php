<?php

namespace App\Http\Requests\V1\FCH\Common\Appointment;

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
            'appointment_date' => 'required|date_format:Y-m-d H:i:s',
            'timezone' => 'required|string',
            'duration_minutes' => 'required|integer|min:1',
            'status' => ['nullable', Rule::in(array_keys(Appointment::STATUSES))],
            'meeting_type' => ['required', Rule::in(array_keys(Appointment::MEETING_TYPES))],
            'location' => 'nullable|string|max:255',
            'assigned_for' => 'nullable|exists:users,id',
            'remarks' => 'nullable|string',
        ];
    }
}
