<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ActivityLog;

use App\Http\Requests\Api\V1\BaseApiRequest;

class StoreEmailRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'borrower_id'   => ['nullable', 'exists:borrowers,id'],
            'partner_id'    => ['nullable', 'exists:partners,id'],
            'to_emails'     => ['required'],
            'subject'       => ['required', 'string', 'max:500'],
            'description'   => ['required', 'string'],
            'attachments'   => ['nullable', 'array'],
            'attachments.*' => ['string'],
        ];
    }

    public function messages(): array
    {
        return [
            'to_emails.required'   => __('activity_log.to_emails_required'),
            'subject.required'     => __('activity_log.subject_required'),
            'description.required' => __('activity_log.description_required'),
        ];
    }
}
