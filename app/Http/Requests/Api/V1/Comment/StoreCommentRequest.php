<?php

namespace App\Http\Requests\Api\V1\Comment;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Common\Comment;
use Illuminate\Validation\Rule;

class StoreCommentRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_type' => ['required', Rule::in(array_keys(Comment::ALLOWED_ENTITY_TYPES))],
            'entity_id' => ['required', 'integer'],
            'body' => ['required', 'string', 'max:5000'],
            'visibility' => ['nullable', Rule::in(ActivityLog::VISIBILITY_LEVELS)],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'The comment message is required.',
            'body.string' => 'The comment message must be a valid text string.',
            'body.max' => 'The comment message cannot exceed 5000 characters.',
        ];
    }

    public function attributes(): array
    {
        return [
            'body' => 'comment message',
        ];
    }
}
