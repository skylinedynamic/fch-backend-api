<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Calendly;

use App\Http\Requests\Api\V1\BaseApiRequest;

/** The open slots of a Calendly event type on one day, in the viewer's zone. */
class CalendlyAvailableTimesRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_type_uri' => 'required|string|url|max:255',
            'date' => 'required|date_format:Y-m-d',
            'timezone' => 'nullable|string|timezone:all',
            // The person the host is booking with, so their FCH meetings are left out too.
            'assigned_for' => 'nullable|integer|exists:users,id',
        ];
    }
}
