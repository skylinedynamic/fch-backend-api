<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ActivityLog\Concerns;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "Want to Assign" on a Task activity, the same on New Activity and Edit:
 * who does it, by when, how urgent and where it stands.
 *
 * The request says which team the assignee must come from, what the
 * Reference is, and (on edit) the due date already saved - a task may keep a
 * due date that has since passed, only a newly picked one must not be past.
 */
trait ValidatesTaskAssignment
{
    /** The partner whose staff may take the task (the list "Assigned To" offered). */
    abstract protected function taskTeamPartner(): ?Partner;

    /** The activity's Reference once this request is applied. */
    abstract protected function taskReference(): ?string;

    protected function savedTaskDueDate(): ?string
    {
        return null;
    }

    public function assignsTask(): bool
    {
        return filter_var($this->input('assign_task', false), FILTER_VALIDATE_BOOLEAN);
    }

    protected function taskAssignmentRules(): array
    {
        return [
            'assign_task'   => ['nullable', 'in:1,0,true,false'],
            'assigned_to'   => [
                Rule::requiredIf($this->assignsTask()),
                'nullable',
                'integer',
                function ($attribute, $value, $fail) {
                    if ($this->assignsTask() && ! $this->isEligibleAssignee((int) $value)) {
                        $fail('The selected staff member cannot be assigned this task.');
                    }
                },
            ],
            'task_due_date' => [Rule::requiredIf($this->assignsTask()), 'nullable', 'date_format:Y-m-d'],
            'task_priority' => [Rule::requiredIf($this->assignsTask()), 'nullable', Rule::in(array_keys(ActivityLog::TASK_PRIORITY_LABELS))],
            'task_status'   => [Rule::requiredIf($this->assignsTask()), 'nullable', Rule::in(array_keys(ActivityLog::TASK_STATUS_LABELS))],
        ];
    }

    protected function taskAssignmentMessages(): array
    {
        return [
            'assigned_to.required'      => 'Please select who the task is assigned to.',
            'assigned_to.integer'       => 'The selected staff member cannot be assigned this task.',
            'task_due_date.required'    => 'Please select when the task is due.',
            'task_due_date.date_format' => 'The due date is not a valid date.',
            'task_priority.required'    => 'Please select a priority.',
            'task_priority.in'          => 'Priority must be Low, Medium or High.',
            'task_status.required'      => 'Please select a status.',
            'task_status.in'            => 'Status must be Open, In Progress or Complete.',
        ];
    }

    protected function validateTaskAssignment(Validator $validator): void
    {
        if (! $this->assignsTask()) {
            return;
        }

        if ($this->taskReference() !== ActivityLog::TASK_REFERENCE) {
            $validator->errors()->add('assign_task', 'Only a Task activity can be assigned.');
        }

        // A calendar day: today counts, judged by the viewer's own date.
        $dueDate = $this->input('task_due_date');
        if (! $validator->errors()->has('task_due_date') && ! empty($dueDate) && $dueDate !== $this->savedTaskDueDate()) {
            $timezone = $this->header('X-Timezone') ?: config('app.timezone');
            if (Carbon::parse($dueDate, $timezone)->lt(Carbon::today($timezone))) {
                $validator->errors()->add('task_due_date', 'The due date cannot be in the past.');
            }
        }
    }

    /**
     * For the host, the team of the partner that owns the record (the host's
     * own when it has none, as on a Partner's page); anyone else has only
     * their own team - the same rule as common/get-all-users.
     */
    protected function teamForRecordOwner(?int $ownerPartnerId): ?Partner
    {
        $own = $this->user()?->partner;
        if (! $own || ! $own->is_host) {
            return $own;
        }

        return ($ownerPartnerId ? Partner::find($ownerPartnerId) : null) ?? $own;
    }

    private function isEligibleAssignee(int $userId): bool
    {
        $partner = $this->taskTeamPartner();

        return $partner !== null
            && User::query()->teamOf($partner)->whereKey($userId)->exists();
    }
}
