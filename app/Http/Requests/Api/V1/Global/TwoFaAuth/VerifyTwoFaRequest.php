<?php

namespace App\Http\Requests\Api\V1\Global\TwoFaAuth;

use App\Models\TwoFaAuth\TwoFaAuthSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Helpers\ApiResponse;
use Illuminate\Validation\Rule;

class VerifyTwoFaRequest extends FormRequest
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


           /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            ApiResponse::validationError($validator->errors(), __('auth.validation_failed'))
        );
    }
}