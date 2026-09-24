<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ActivityLog;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\ActivityLog\ActivityLog;
use Illuminate\Validation\Rule;

class UpdateActivityLogVisibilityRequest extends BaseApiRequest
{
    // Only FCH host users may hit this endpoint at all — enforced in
    // ActivityLogService::updateVisibility() via canChangeVisibilityBy(),
    // consistent with how other 403s in this module are thrown from the
    // service layer rather than authorize().
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'visibility' => ['required', Rule::in(ActivityLog::VISIBILITY_LEVELS)],
        ];
    }

    public function messages(): array
    {
        return [
            'visibility.required' => 'Please select a visibility level.',
            'visibility.in'       => 'Invalid visibility level.',
        ];
    }
}
