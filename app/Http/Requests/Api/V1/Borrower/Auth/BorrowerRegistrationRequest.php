<?php

namespace App\Http\Requests\Api\V1\Borrower\Auth;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;
use App\Rules\ReCaptcha;
use Illuminate\Validation\Rules\Password;

class BorrowerRegistrationRequest extends BaseApiRequest
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
            'signup_id' => ['required','string',Rule::exists('signup_ids', 'signup_id')],
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone_number' => 'required|string|max:20',
            'state' => 'required|string|max:255',
            'password' => [
                'required',
                'string',
                Password::min(8)->mixedCase()->numbers(),
                'confirmed',
            ],
            'password_confirmation' => ['required', 'string', 'min:8'],
            'recaptcha' => ['nullable', new ReCaptcha()],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'signup_id.required' => __('auth.signup_id_required'),
            'signup_id.exists' => __('auth.signup_id_exists'),
            'first_name.required' => __('auth.first_name_required'),
            'last_name.required' => __('auth.last_name_required'),
            'email.required' => __('auth.email_required'),
            'email.email' => __('auth.email_email'),
            'email.unique' => __('auth.email_unique'),
            'phone_number.required' => __('auth.phone_number_required'),
            'state.required' => __('auth.state_required'),
            'password.required' => __('auth.password_required'),
            'password.min' => __('auth.password_min'),
            'password.confirmed' => __('auth.password_confirmed'),
            'recaptcha.required' => __('auth.recaptcha_required'),
        ];
    }
}