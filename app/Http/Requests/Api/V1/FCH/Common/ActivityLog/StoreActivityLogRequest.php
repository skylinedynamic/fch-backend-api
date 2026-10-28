<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ActivityLog;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Http\Requests\Api\V1\FCH\Common\ActivityLog\Concerns\ValidatesTaskAssignment;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use Illuminate\Validation\Rule;
use Illuminate\Http\UploadedFile;

class StoreActivityLogRequest extends BaseApiRequest
{
    use ValidatesTaskAssignment;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Support fallback if frontend sends `to_email` instead of `to_emails`
        if ($this->has('to_email') && !$this->has('to_emails')) {
            $this->merge([
                'to_emails' => $this->input('to_email'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'log_type'      => ['required', Rule::in([ActivityLog::TYPE_ACTIVITY, ActivityLog::TYPE_EMAIL])],
            'borrower_id'   => ['nullable', 'exists:borrowers,id'],
            'partner_id'    => ['nullable', 'exists:partners,id'],
            'lead_id'       => ['nullable', 'exists:leads,id'],
            'funding_request_id'      => ['nullable', 'exists:funding_requests,id'],
            'funding_application_id'  => ['nullable', 'exists:funding_applications,id'],
            
            'send_to_host' => ['nullable', 'in:1,0,true,false'],
            'send_to_borrower' => ['nullable', 'in:1,0,true,false'],
            'visible_to_host' => ['nullable', 'in:1,0,true,false'],
            'visible_to_borrower' => ['nullable', 'in:1,0,true,false'],

            'visibility' => [
                Rule::requiredIf($this->log_type === ActivityLog::TYPE_ACTIVITY),
                'nullable',
                Rule::in($this->allowedVisibilities()),
            ],

            'subject'       => ['required', 'string', 'max:200'],
            'description'   => ['required', 'string'],

            'to_emails' => [
                Rule::requiredIf($this->log_type === ActivityLog::TYPE_EMAIL),
                'nullable',
                function ($attribute, $value, $fail) {
                    if (empty($value)) return;

                    $recipients = $value;
                    if (is_string($recipients)) {
                        $decoded = json_decode($recipients, true);
                        $recipients = is_array($decoded) ? $decoded : array_map('trim', explode(',', $recipients));
                    }

                    if (!is_array($recipients)) {
                        $recipients = [$recipients];
                    }

                    foreach ($recipients as $email) {
                        if (empty($email)) continue;
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $fail(__('activity_log.to_emails_invalid'));
                            return;
                        }
                    }
                }
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

            // "Want to Assign" on a Task
            ...$this->taskAssignmentRules(),

            // Attachments array
            'attachments'   => ['nullable', 'array'],
            'attachments.*' => ['file'], // basic file check
            
            // Reminders
            'remind_me'     => ['nullable', 'in:1,0,true,false'],
            'remind_at'     => ['nullable', 'required_if:remind_me,1,true', 'date'],
        ];
    }

    /**
     * Host users may address an activity at any of the 4 levels. Partner
     * users may not tag anything "Internal (FCH Only)" — meaningless from
     * their side, and letting them set it would just hide their own note
     * from themselves next time a host reviews it.
     */
    private function allowedVisibilities(): array
    {
        if (ActivityLog::isHostUser($this->user())) {
            return ActivityLog::VISIBILITY_LEVELS;
        }

        return [
            ActivityLog::VISIBILITY_PRIVATE,
            ActivityLog::VISIBILITY_PARTNER,
            ActivityLog::VISIBILITY_EVERYONE,
        ];
    }

    protected function taskReference(): ?string
    {
        return $this->input('reference');
    }

    /**
     * The owner of the lead / client / funding request / funding application
     * the activity is logged on; a Partner's own page names none.
     */
    protected function taskTeamPartner(): ?Partner
    {
        return $this->teamForRecordOwner(match (true) {
            $this->filled('lead_id') => Lead::whereKey($this->input('lead_id'))->value('partner_id'),
            $this->filled('borrower_id') => Borrower::whereKey($this->input('borrower_id'))->value('partner_id'),
            $this->filled('funding_request_id') => FundingRequest::whereKey($this->input('funding_request_id'))->value('partner_id'),
            $this->filled('funding_application_id') => FundingApplication::whereKey($this->input('funding_application_id'))->value('partner_id'),
            default => null,
        });
    }

    public function messages(): array
    {
        return [
            ...$this->taskAssignmentMessages(),
            'visibility.required'    => 'Please select who can see this activity.',
            'visibility.in'          => 'That visibility level is not available to you.',
            'to_emails.required'     => __('activity_log.to_emails_required'),
            'to_emails.email'        => __('activity_log.to_emails_invalid'),
            'to_emails.string'       => __('activity_log.to_emails_invalid'),
            'to_emails.max'          => __('validation.max.string', ['attribute' => 'email', 'max' => 255]),
            'reference.required'     => __('activity_log.reference_required'),
            'activity_date.required' => __('activity_log.activity_date_required'),
            'subject.required'       => __('activity_log.subject_required'),
            'subject.max'            => 'The summary may not be greater than 200 characters.',
            'description.required'   => __('activity_log.description_required'),
            'attachments.*.file'     => __('activity_log.attachment_file'),
            'remind_at.required_if'  => 'A reminder time is required when setting a reminder.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validateTaskAssignment($validator);

            $allowedExtensions = [
                'jpg','jpeg','png','pdf','gif','webp','svg',
                'xls','xlsx','doc','docx','ppt','pptx','txt',
                'zip','rar','csv'
            ];
            $maxSizeBytes = 5 * 1024 * 1024; // 5MB total limit

            $attachments = $this->file('attachments', []);
            $totalSize = 0;

            foreach ($attachments as $index => $file) {
                if (!($file instanceof UploadedFile)) {
                    $validator->errors()->add("attachments.{$index}", "File could not be read.");
                    continue;
                }

                $ext = strtolower($file->getClientOriginalExtension());
                $name = $file->getClientOriginalName();

                if (!in_array($ext, $allowedExtensions)) {
                    $validator->errors()->add("attachments.{$index}", "Allowed: " . implode(', ', $allowedExtensions));
                }

                // Add to total
                $totalSize += $file->getSize();

                // Check individual file error states (e.g. hits PHP native limits)
                if ($file->getError() === UPLOAD_ERR_INI_SIZE) {
                    $validator->errors()->add("attachments", "Total attachment size may not be greater than 5MB.");
                }
            }

            // Check if combined total exceeds 5MB
            if ($totalSize > $maxSizeBytes) {
                $validator->errors()->add("attachments", "Total attachment size may not be greater than 5MB.");
            }
        });
    }
}