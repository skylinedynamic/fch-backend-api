<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ActivityLog;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\ActivityLog\ActivityLog;
use Illuminate\Validation\Rule;

/**
 * A task's status changed from its reminder. Who may change it (the task's
 * creator or assignee) is checked in ActivityLogService::updateTaskStatus(),
 * like the module's other 403s.
 */
class UpdateActivityLogTaskStatusRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task_status' => ['required', Rule::in(array_keys(ActivityLog::TASK_STATUS_LABELS))],
        ];
    }

    public function messages(): array
    {
        return [
            'task_status.required' => 'Please select a status.',
            'task_status.in'       => 'Status must be Open, In Progress or Complete.',
        ];
    }
}
