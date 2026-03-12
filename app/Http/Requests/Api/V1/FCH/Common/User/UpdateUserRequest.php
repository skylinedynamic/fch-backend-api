<?php

namespace App\Http\Requests\Api\V1\FCH\Common\User;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {

        $rules = [
            'name'             => ['required', 'string', 'max:255'],
            'email'            => ['required','email',Rule::unique('users', 'email')->ignore($this->user->id)->whereNull('deleted_at')],
            'phone_number'     => ['required', 'string', 'max:50', Rule::unique('users', 'phone_number')->ignore($this->user->id)->whereNull('deleted_at')],
            'user_id'          => ['nullable', 'string', 'max:255'],
            'role_id'          => ['required'],
            'status'           => ['required'],
            'change_password'  => ['nullable'],
        ];

        // Explicitly add password rules if change_password is provided and true
        if ($this->boolean('change_password')) {
            $rules['new_password'] = ['required', 'string', 'min:8'];
            $rules['confirm_password'] = ['required', 'string', 'same:new_password'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'email.unique'              => __('user.email_taken'),
            'phone_number.unique'       => __('user.phone_number_taken'),
            'password.min'              => __('user.password_min'),
            'new_password.required'     => __('user.new_password_required'),
            'new_password.min'          => __('user.new_password_min'),
            'confirm_password.required' => __('user.confirm_password_required'),
            'confirm_password.same'     => __('user.confirm_password_same'),
        ];
    }
}
