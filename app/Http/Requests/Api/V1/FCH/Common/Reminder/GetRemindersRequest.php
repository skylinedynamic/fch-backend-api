<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Reminder;

use Illuminate\Contracts\Validation\ValidationRule;
use App\Http\Requests\Api\V1\BaseApiRequest;

class GetRemindersRequest extends BaseApiRequest
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
            'per_page' => 'nullable|integer|min:1|max:100',
            'sort_by' => 'nullable|string|in:created_at,send_at,status,summary,set_date_time,reference',
            'sort_order' => 'nullable|string|in:asc,desc',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'reference_type' => 'nullable|string',
            'status' => 'nullable|string',
            'search' => 'nullable|string|max:255',
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
            'per_page.integer' => 'Per page must be an integer.',
            'per_page.min' => 'Per page must be at least 1.',
            'per_page.max' => 'Per page may not be greater than 100.',
            'sort_by.in' => 'The selected sort by is invalid.',
            'sort_order.in' => 'The selected sort order is invalid.',
            'start_date.date_format' => 'The start date does not match the format Y-m-d.',
            'end_date.date_format' => 'The end date does not match the format Y-m-d.',
            'end_date.after_or_equal' => 'The end date must be a date after or equal to start date.',
            'search.max' => 'The search may not be greater than 255 characters.',
        ];
    }
}
