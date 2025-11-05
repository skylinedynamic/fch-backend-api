<?php

namespace App\Http\Requests\Api\V1\Global\TwoFaAuth;

use App\Http\Requests\Api\V1\BaseApiRequest;

class RegenerateRecoveryCodesRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        // Allow regeneration via this endpoint; apply additional gate/policy if needed later
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.integer' => __('auth.login_2fa_user_id_integer'),
            'user_id.exists' => __('auth.login_2fa_user_id_exists'),
        ];
    }
}