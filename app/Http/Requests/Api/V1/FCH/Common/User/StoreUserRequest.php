<?php

namespace App\Http\Requests\Api\V1\FCH\Common\User;

use App\Helpers\ApiResponse;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

use App\Models\Employee\Employee;
use Illuminate\Validation\Rule;
use App\Rules\UsPhoneNumber;
use App\Rules\AssignableRole;

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
            'email'        => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'user_id'      => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', new UsPhoneNumber(), Rule::unique('users', 'phone_number')->whereNull('deleted_at')],
            'password'     => ['required', 'string', 'min:6'],
            'role_id'      => ['required', new AssignableRole($this->user(), __('user.role_not_found'))],
            'employee_id'  => [
                'required',
                // Only this partner's own (non-deleted) employees...
                Rule::exists('employees', 'id')
                    ->where('partner_id', $this->user()?->partner_id)
                    ->whereNull('deleted_at'),
                // ...that are not already linked to a user.
                Rule::unique('users', 'entity_id')
                    ->where('entity_type', Employee::class)
                    ->whereNull('deleted_at'),
            ],
            'is_paid'      => ['nullable', 'boolean'],
            'start_date'   => ['nullable', 'date'],
            'end_date'     => ['nullable', 'date'],
            'months'       => ['nullable', 'integer', 'min:1'],
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
            'employee_id.required' => __('user.employee_required'),
            'employee_id.exists'   => __('user.employee_not_found'),
            'employee_id.unique'   => __('user.employee_already_linked'),
        ];
    }
}
