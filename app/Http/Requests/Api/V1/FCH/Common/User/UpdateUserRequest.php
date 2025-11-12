<?php

namespace App\Http\Requests\Api\V1\FCH\Common\User;

use App\Helpers\ApiResponse;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        
        return [
            'name'         => ['sometimes', 'string', 'max:255'],
             'email' => ['nullable', 'max:255'],
            'phone_number' => [
                'nullable',
                'string',
                'max:50'
            ],
            'user_id'      => ['nullable', 'string', 'max:255'],
            'password'     => ['nullable', 'string', 'min:6'],
            'role_id'      => ['sometimes', 'exists:roles,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'      => __('user.email_taken'),
            'phone_number.unique' => __('user.phone_number_taken'),
            'password.min'      => __('user.password_min'),
            'role_id.exists'    => __('user.role_not_found'),
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            $errors[$field] = $messages[0];
        }

        throw new HttpResponseException(
            ApiResponse::validationError($errors, __('auth.validation_failed'))
        );
    }
}
