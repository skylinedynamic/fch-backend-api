<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Calendly;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Appointment\Appointment;
use Illuminate\Validation\Rule;

/**
 * A booking into the company Calendly: one of its event types at one of the
 * open slots Calendly offered. Who it is with is the caller - or, for the
 * host, assigned_for (checked by the service, which knows who the host is).
 */
class BookCalendlyAppointmentRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_type_uri' => 'required|string|url|max:255',
            'start_time' => 'required|date|after:now',
            'timezone' => 'required|string|timezone:all',
            'subject' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'meeting_type' => ['nullable', Rule::in(array_keys(Appointment::MEETING_TYPES))],
            'assigned_for' => 'nullable|integer|exists:users,id',
        ];
    }
}
