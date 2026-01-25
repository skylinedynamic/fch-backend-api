<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Application;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Borrower\PreQualificationRequest;
use Illuminate\Validation\Rule;

class UpdatePreQualificationStatusRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id'            => ['required', 'integer', 'exists:pre_qualification_requests,id'],
            'status'        => ['required', Rule::in(PreQualificationRequest::STATUS)],
            'status_note'   => ['nullable', 'string', 'max:1000'],
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
            'id.required' => __('application.id_required'),
            'id.integer' => __('application.id_integer'),
            'id.exists' => __('application.id_exists'),

            'status.required' => __('application.status_required'),
            'status.in' => __('application.status_in'),

            'status_note.string' => __('application.status_note_string'),
            'status_note.max' => __('application.status_note_max'),
        ];
    }
}
