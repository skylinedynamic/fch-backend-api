<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Appointment;

use App\Http\Requests\Api\V1\BaseApiRequest;

class CheckAvailabilityRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'appointment_date' => 'required',
            'assigned_for' => 'nullable|integer|exists:users,id',
        ];
    }

    public function messages(): array
    {
        return [
            'appointment_date.required' => __('appointment.date_required'),
            'appointment_date.date_format' => __('appointment.date_invalid'),
            'assigned_for.exists' => __('appointment.assigned_for_invalid'),
        ];
    }
}
