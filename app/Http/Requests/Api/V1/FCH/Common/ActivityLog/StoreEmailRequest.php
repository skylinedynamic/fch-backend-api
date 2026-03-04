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
            'lead_id'       => ['nullable', 'exists:leads,id'],
            'borrower_id'   => ['nullable', 'exists:borrowers,id'],
            'to_emails'     => ['required', 'array', 'min:1'],
            'to_emails.*'   => ['required', 'email'],
            'subject'       => ['required', 'string', 'max:500'],
            'message_body'  => ['required', 'string'],
            'attachments'   => ['nullable', 'array'],
            'attachments.*' => ['string'],
        ];
    }

    public function messages(): array
    {
        return [
            'to_emails.required'   => __('activity_log.to_emails_required'),
            'to_emails.min'        => __('activity_log.to_emails_min'),
            'to_emails.*.email'    => __('activity_log.to_emails_invalid'),
            'subject.required'     => __('activity_log.subject_required'),
            'message_body.required' => __('activity_log.message_body_required'),
        ];
    }
}
