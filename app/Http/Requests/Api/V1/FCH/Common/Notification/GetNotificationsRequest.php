<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Notification;

use App\Http\Requests\Api\V1\BaseApiRequest;

class GetNotificationsRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:' . config('notifications.inbox.max_per_page', 50),
            'status' => 'nullable|string|in:all,unread,read',
        ];
    }
}
