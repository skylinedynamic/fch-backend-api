<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ActivityLog;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\ActivityLog\ActivityLog;
use Illuminate\Validation\Rule;

class StoreActivityLogRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'log_type'      => ['required', Rule::in([ActivityLog::TYPE_ACTIVITY, ActivityLog::TYPE_EMAIL])],
            'entity_type'   => ['nullable', 'string', 'max:255'],
            'entity_id'     => ['nullable', 'integer'],
            'borrower_id'   => ['nullable', 'exists:borrowers,id'],
            'partner_id'    => ['nullable', 'exists:partners,id'],
            
            // Shared required fields
            'subject'       => ['required', 'string', 'max:500'],
            'description'   => ['required', 'string'],
            
            // Form specific fields
            'to_emails' => [
                Rule::requiredIf($this->log_type === ActivityLog::TYPE_EMAIL),
                'nullable',
                'string',
                'email',
                'max:255'
            ],
            
            'reference'     => [
                Rule::requiredIf($this->log_type === ActivityLog::TYPE_ACTIVITY),
                'nullable',
                'string',
                'max:255'
            ],
            'activity_date' => [
                Rule::requiredIf($this->log_type === ActivityLog::TYPE_ACTIVITY),
                'nullable',
                'date'
            ],
            'category'      => ['nullable', 'string', 'max:255'],
            'assigned_to'   => ['nullable', 'exists:users,id'],
            'attachments'   => ['nullable', 'array'],
            'attachments.*' => ['string'],
        ];
    }

    public function messages(): array
    {
        return [
            'to_emails.required'     => __('activity_log.to_emails_required'),
            'reference.required'     => __('activity_log.reference_required'),
            'activity_date.required' => __('activity_log.activity_date_required'),
            'subject.required'       => __('activity_log.subject_required'),
            'description.required'   => __('activity_log.description_required'),
        ];
    }
}
