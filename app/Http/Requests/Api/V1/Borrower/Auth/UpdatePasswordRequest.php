<?php

namespace App\Http\Requests\Api\V1\Borrower\Auth;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Foundation\Http\FormRequest;
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
            'current_password.required' => __('borrower_validation.current_password_required'),
            'new_password.required' => __('borrower_validation.new_password_required'),
            'new_password.min' => __('borrower_validation.new_password_min'),
            // The Password rule reports these as "password.<check>", not "<check>".
            'new_password.password.mixed' => __('borrower_validation.new_password_mixed'),
            'new_password.password.letters' => __('borrower_validation.new_password_letters'),
            'new_password.password.numbers' => __('borrower_validation.new_password_numbers'),
            'new_password.password.symbols' => __('borrower_validation.new_password_symbols'),
            'new_password.confirmed' => __('borrower_validation.new_password_confirmed'),
            'new_password.different' => __('borrower_validation.new_password_different'),
        ];
    }
}
