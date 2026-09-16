<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Reminder;

use Illuminate\Contracts\Validation\ValidationRule;
use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Support\Carbon;

class SnoozeReminderRequest extends BaseApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // The raw reschedule_at string is naive local time, interpreted using
        // this same header in ReminderService::snoozeReminder() — comparing it
        // to "now" here has to use the same timezone or a future local time
        // near a UTC offset boundary could be wrongly rejected/accepted.
        $timezone = $this->header('X-Timezone', config('app.timezone'));

        return [
            'minutes' => 'required_without:reschedule_at|nullable|integer|min:1|max:1440',
            'reschedule_at' => [
                'bail',
                'required_without:minutes',
                'nullable',
                'date',
                function ($attribute, $value, $fail) use ($timezone) {
                    if ($value && Carbon::parse($value, $timezone)->isPast()) {
                        $fail('The reschedule date/time must be in the future.');
                    }
                },
            ],
            // How long before reschedule_at to fire the alert (e.g. "15 minutes
            // before"), matching the same dropdown activity creation uses. Only
            // meaningful alongside reschedule_at; ignored for a plain minutes-snooze.
            'lead_minutes' => 'nullable|integer|min:0',
            // Optional reason for snoozing, kept in the reminder's snooze history.
            'note' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'minutes.required_without' => 'Either minutes or a reschedule date/time is required.',
            'minutes.integer' => 'The minutes must be an integer.',
            'minutes.min' => 'The minutes must be at least 1.',
            'minutes.max' => 'The snooze may not be longer than 1440 minutes (24 hours).',
            'note.max' => 'The snooze note may not be greater than 1000 characters.',
            'reschedule_at.required_without' => 'Either minutes or a reschedule date/time is required.',
            'reschedule_at.date' => 'The reschedule date/time is not valid.',
        ];
    }
}
