<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ActivityLog;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Http\Requests\Api\V1\FCH\Common\ActivityLog\Concerns\ValidatesTaskAssignment;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Partner\Partner;
use Illuminate\Validation\Rule;

class UpdateActivityLogRequest extends BaseApiRequest
{
    use ValidatesTaskAssignment;

    private ActivityLog|false|null $activityLog = false;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // "Want to Assign" on a Task - the assignee can be changed here
            ...$this->taskAssignmentRules(),

            'subject'       => ['sometimes', 'required', 'string', 'max:200'],
            'description'   => ['nullable', 'string'],
            'reference'     => ['nullable', 'string', 'max:255'],
            'activity_date' => ['nullable', 'date'],
            // The owner may change their own manual activity's visibility
            // here; a host reclassifying someone else's/system's row uses
            // the separate visibility-only endpoint instead.
            'visibility'    => ['nullable', Rule::in(ActivityLog::VISIBILITY_LEVELS)],

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
            ...$this->taskAssignmentMessages(),
            'subject.required'      => __('activity_log.subject_required'),
            'subject.max'           => 'The summary may not be greater than 200 characters.',
            'remind_at.required_if' => 'A reminder time is required when setting a reminder.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(fn ($validator) => $this->validateTaskAssignment($validator));
    }

    /** The activity being edited (null if it doesn't exist - the service answers that). */
    private function activityLog(): ?ActivityLog
    {
        if ($this->activityLog === false) {
            $this->activityLog = ActivityLog::with('subject')->find($this->route('id'));
        }

        return $this->activityLog;
    }

    protected function taskReference(): ?string
    {
        return $this->filled('reference')
            ? $this->input('reference')
            : ($this->activityLog()?->properties['reference'] ?? null);
    }

    protected function savedTaskDueDate(): ?string
    {
        return $this->activityLog()?->properties['task']['due_date'] ?? null;
    }

    /**
     * The owner of the record the activity is logged on; on a Partner's own
     * page that is the partner itself, whose tasks go to the host's staff.
     */
    protected function taskTeamPartner(): ?Partner
    {
        $subject = $this->activityLog()?->subject;

        return $this->teamForRecordOwner(
            $subject && ! $subject instanceof Partner ? $subject->partner_id : null
        );
    }
}
