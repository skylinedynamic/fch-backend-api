<?php

namespace App\Http\Requests\Api\V1\Comment;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UpdateCommentRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
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
