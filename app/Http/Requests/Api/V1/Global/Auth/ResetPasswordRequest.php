<?php

namespace App\Http\Requests\Api\V1\Global\Auth;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            // Enforce: min 8, at least lowercase, uppercase, and number
            'password' => [
                'required',
                'string',
                Password::min(8)->mixedCase()->numbers(),
                'confirmed',
            ],
            'password_confirmation' => ['required', 'string', 'min:8'],
        ];
    }
}