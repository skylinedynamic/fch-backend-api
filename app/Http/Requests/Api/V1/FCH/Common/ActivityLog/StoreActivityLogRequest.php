<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ActivityLog;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\ActivityLog\ActivityLog;
use Illuminate\Validation\Rule;
use Illuminate\Http\UploadedFile;

class StoreActivityLogRequest extends BaseApiRequest
{
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
            'entity_type'   => ['nullable', 'string', 'max:255'],
            'entity_id'     => ['nullable', 'integer'],
            'borrower_id'   => ['nullable', 'exists:borrowers,id'],
            'partner_id'    => ['nullable', 'exists:partners,id'],
            'lead_id'       => ['nullable', 'exists:leads,id'],

            'subject'       => ['required', 'string', 'max:500'],
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
            'assigned_to'   => ['nullable', 'exists:users,id'],

            // Attachments array
            'attachments'   => ['nullable', 'array'],
            'attachments.*' => ['file'], // basic file check
        ];
    }

    public function messages(): array
    {
        return [
            'to_emails.required'     => __('activity_log.to_emails_required'),
            'to_emails.email'        => __('activity_log.to_emails_invalid'),
            'to_emails.string'       => __('activity_log.to_emails_invalid'),
            'to_emails.max'          => __('validation.max.string', ['attribute' => 'email', 'max' => 255]),
            'reference.required'     => __('activity_log.reference_required'),
            'activity_date.required' => __('activity_log.activity_date_required'),
            'subject.required'       => __('activity_log.subject_required'),
            'description.required'   => __('activity_log.description_required'),
            'attachments.*.file'     => __('activity_log.attachment_file'),
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
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