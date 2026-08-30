<?php

namespace App\Http\Requests\Api\V1\FCH\Common\AuthProfile;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends BaseApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required'],
            'new_password' => [
                'required',
                'confirmed',
                'different:current_password',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'current_password.required' => __('profile.current_password_required'),
            'new_password.required' => __('profile.new_password_required'),
            'new_password.min' => __('profile.new_password_min'),
            'new_password.mixed' => __('profile.new_password_mixed'),
            'new_password.letters' => __('profile.new_password_letters'),
            'new_password.numbers' => __('profile.new_password_numbers'),
            'new_password.symbols' => __('profile.new_password_symbols'),
            'new_password.confirmed' => __('profile.new_password_confirmed'),
            'new_password.different' => __('profile.new_password_different'),
        ];
    }
}
