<?php

namespace App\Http\Requests\Api\V1\Global\Auth;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Rules\ReCaptcha;

class LoginRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string','min:6'],
            'recaptcha' => ['sometimes','nullable', new ReCaptcha()],
        ];
    }

    
    public function messages(): array
    {
        return [
            'email.required' => __('auth.email_required'),
            'email.email' => __('auth.email_invalid'),
            'password.required' => __('auth.password_required'),
            'password.min' => __('auth.password_min'),
            'recaptcha.required' => __('auth.recaptcha_required'),
        ];
    }

}