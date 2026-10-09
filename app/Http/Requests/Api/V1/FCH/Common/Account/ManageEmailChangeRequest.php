<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Account;

use App\Http\Requests\Api\V1\BaseApiRequest;

class ManageEmailChangeRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
