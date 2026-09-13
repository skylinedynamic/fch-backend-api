<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Employee;

use App\Helpers\ApiResponse;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Rules\UsPostalCode;

class StoreEmployeeRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $partnerId = $this->user()?->partner_id;

        return [
            'first_name'     => ['required', 'string', 'max:255'],
            'last_name'      => ['nullable', 'string', 'max:255'],
            'designation'    => ['required', 'string', 'max:255'],
            'department'     => ['required', 'string', 'max:255'],
            'job_status'     => ['nullable', 'string', 'max:255'],
            'joining_date'   => ['nullable', 'date'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'emergency_phone' => ['nullable', 'string', 'max:50'],
            'phone_number_2' => ['nullable'],
            'phone_number_1'   => [
                'required',
                'string',
                'max:50',
                Rule::unique('employees')->where(fn($q) => $q->where('partner_id', $partnerId)->whereNull('deleted_at')),
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('employees')->where(fn($q) => $q->where('partner_id', $partnerId)->whereNull('deleted_at')),
            ],
            'date_of_birth'  => ['nullable', 'date', 'before:today'],
            'gender'         => ['nullable'],
            'city'           => ['required', 'string', 'max:255'],
            'state'          => ['required', 'string', 'max:255'],
            'postal_code'    => ['nullable', 'string', new UsPostalCode()],
            'country'        => ['required', 'string', 'max:255'],
            'country_code'   => ['nullable'],
            'image'          => ['nullable'],
            'create_account' => ['sometimes', 'boolean'],
            'role_id'        => ['required_if:create_account,1', 'nullable', 'exists:roles,id'],
            'address'        => ['required', 'string', 'max:500'],
            'is_paid'        => ['nullable'],
            'months'         => ['nullable'],
            'amount'         => ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => __('employee.first_name_required'),
            'designation.required' => __('employee.designation_required'),
            'department.required' => __('employee.department_required'),
            'phone_number_1.required' => __('employee.phone_number_required'),
            'phone_number_1.unique' => __('employee.phone_unique'),
            'email.required' => __('employee.email_required'),
            'email.email' => __('employee.email_valid'),
            'email.unique' => __('employee.email_unique'),
            'address.required' => __('employee.address_required'),
            'city.required' => __('employee.city_required'),
            'state.required' => __('employee.state_required'),
            'country.required' => __('employee.country_required'),
            'role_id.required_if' => __('employee.role_required_if_create_account'),
            'role_id.exists' => __('employee.role_exists'),
        ];
    }
}
