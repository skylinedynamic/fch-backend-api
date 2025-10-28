<?php

namespace App\Http\Requests\Api\V1\Global\TwoFaAuth;

use App\Models\TwoFaAuth\TwoFaAuthSetting;
use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;

class VerifyTwoFaRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in([TwoFaAuthSetting::TOTP, TwoFaAuthSetting::SMS, TwoFaAuthSetting::EMAIL])],
            'code' => ['required', 'string', 'min:4', 'max:10'],
        ];
    }
}