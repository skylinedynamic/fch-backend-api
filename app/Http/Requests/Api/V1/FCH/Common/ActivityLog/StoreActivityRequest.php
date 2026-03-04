<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ActivityLog;

use App\Http\Requests\Api\V1\BaseApiRequest;

class StoreActivityRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lead_id'       => ['nullable', 'exists:leads,id'],
            'borrower_id'   => ['nullable', 'exists:borrowers,id'],
            'reference'     => ['required', 'string', 'max:255'],
            'category'      => ['nullable', 'string', 'max:255'],
            'activity_date' => ['required', 'date'],
            'assigned_to'   => ['nullable', 'exists:users,id'],
            'summary'       => ['required', 'string', 'max:500'],
            'message_body'  => ['nullable', 'string'],
            'attachments'   => ['nullable', 'array'],
            'attachments.*' => ['string'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference.required'     => __('activity_log.reference_required'),
            'activity_date.required' => __('activity_log.activity_date_required'),
            'summary.required'       => __('activity_log.summary_required'),
        ];
    }
}
