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

    public function rules(): array
    {
        return [
            'log_type'      => ['required', Rule::in([ActivityLog::TYPE_ACTIVITY, ActivityLog::TYPE_EMAIL])],
            'entity_type'   => ['nullable', 'string', 'max:255'],
            'entity_id'     => ['nullable', 'integer'],
            'borrower_id'   => ['nullable', 'exists:borrowers,id'],
            'partner_id'    => ['nullable', 'exists:partners,id'],

            'subject'       => ['required', 'string', 'max:500'],
            'description'   => ['required', 'string'],

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

            // Attachments array
            'attachments'   => ['nullable', 'array'],
            'attachments.*' => ['file'], // basic file check
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
            $maxSizeBytes = 5 * 1024 * 1024; // 5MB

            $attachments = $this->file('attachments', []);

            foreach ($attachments as $index => $file) {
                if (!($file instanceof UploadedFile)) {
                    $validator->errors()->add("attachments.{$index}", "File could not be read.");
                    continue;
                }

                $ext = strtolower($file->getClientOriginalExtension());
                $name = $file->getClientOriginalName();

                if (!in_array($ext, $allowedExtensions)) {
                    $validator->errors()->add("attachments.{$index}", "File \"{$name}\" has a restricted extension (.{$ext}). Allowed: " . implode(', ', $allowedExtensions));
                }

                if ($file->getSize() > $maxSizeBytes) {
                    $validator->errors()->add("attachments.{$index}", "File \"{$name}\" exceeds max size of 5MB.");
                }
            }
        });
    }
}