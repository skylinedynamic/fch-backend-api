<?php

namespace App\Http\Requests\Api\V1\FCH\Common\User;

use App\Helpers\ApiResponse;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreUserRequest extends  BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:255'],
            'email'        => ['required', 'email', 'max:255', 'unique:users,email'],
            'user_id'      => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:50', 'unique:users,phone_number'],
            'password'     => ['required', 'string', 'min:6'],
            'role_id'      => ['required', 'exists:roles,id'],
            'employee_id'  => ['nullable', 'exists:employees,id'],
            'is_paid'      => ['nullable', 'boolean'],
            'start_date'   => ['nullable', 'date'],
            'end_date'     => ['nullable', 'date'],
            'months'       => ['nullable'],
            'amount'       => ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'        => __('user.name_required'),
            'email.required'       => __('user.email_required'),
            'email.email'          => __('user.email_invalid'),
            'email.unique'         => __('user.email_taken'),
            'password.required'    => __('user.password_required'),
            'password.min'         => __('user.password_min'),
            'role_id.required'     => __('user.role_required'),
            'role_id.exists'       => __('user.role_not_found'),
        ];
    }
}
