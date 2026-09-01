<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Reminder;

use Illuminate\Contracts\Validation\ValidationRule;
use App\Http\Requests\Api\V1\BaseApiRequest;

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
        return [
            'minutes' => 'required|integer|min:1',
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
            'minutes.required' => 'The minutes field is required.',
            'minutes.integer' => 'The minutes must be an integer.',
            'minutes.min' => 'The minutes must be at least 1.',
        ];
    }
}
