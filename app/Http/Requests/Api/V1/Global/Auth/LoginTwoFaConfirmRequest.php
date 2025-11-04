<?php

namespace App\Http\Requests\Api\V1\Global\Auth;

use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Contracts\Validation\Validator;

class LoginTwoFaConfirmRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'method' => ['required', 'in:totp,sms,email'],
            'code' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => __('auth.login_2fa_user_id_required'),
            'user_id.integer' => __('auth.login_2fa_user_id_integer'),
            'user_id.exists' => __('auth.login_2fa_user_id_exists'),
            'method.required' => __('auth.login_2fa_method_required'),
            'method.in' => __('auth.login_2fa_method_in'),
            'code.required' => __('auth.login_2fa_code_required'),
            'code.string' => __('auth.login_2fa_code_string'),
        ];
    }
}