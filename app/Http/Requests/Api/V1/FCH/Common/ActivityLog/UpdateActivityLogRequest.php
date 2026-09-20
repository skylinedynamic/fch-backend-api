<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ActivityLog;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UpdateActivityLogRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject'       => ['sometimes', 'required', 'string', 'max:200'],
            'description'   => ['nullable', 'string'],
            'reference'     => ['nullable', 'string', 'max:255'],
            'activity_date' => ['nullable', 'date'],

            'existing_attachments' => ['nullable', 'json'],
            'attachments'          => ['nullable', 'array'],
            'attachments.*'        => ['file', 'mimes:jpg,jpeg,png,pdf,gif,webp,svg,xls,xlsx,doc,docx,ppt,pptx,txt,zip,rar,csv', 'max:5120'],

            'remind_me'           => ['nullable', 'in:1,0,true,false'],
            'remind_at'           => ['nullable', 'required_if:remind_me,1,true', 'date'],
            'reminder_preset'     => ['nullable', 'string', 'max:50'],
            'reminder_time_value' => ['nullable', 'numeric', 'min:0'],
            'reminder_time_unit'  => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'subject.required'      => __('activity_log.subject_required'),
            'subject.max'           => 'The summary may not be greater than 200 characters.',
            'remind_at.required_if' => 'A reminder time is required when setting a reminder.',
        ];
    }
}
