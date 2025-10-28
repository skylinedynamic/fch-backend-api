<?php

namespace App\Http\Requests\Api\V1\Global\TwoFaAuth;

use App\Http\Requests\Api\V1\BaseApiRequest;

class SetupTwoFaRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'method' => 'required|in:totp,sms,email',
        ];
    }
}