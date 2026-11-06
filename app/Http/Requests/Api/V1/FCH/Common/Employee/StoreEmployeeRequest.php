<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Employee;

use App\Helpers\ApiResponse;
use App\Models\Employee\Employee;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Rules\UsPostalCode;
use App\Rules\UsPhoneNumber;
use App\Rules\SafeUpload;

class StoreEmployeeRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $partnerId = $this->user()?->partner_id;

        // Only the name, email and country are required; every other detail
        // may be left empty and filled in later.
        return [
            'first_name'     => ['required', 'string', 'max:255'],
            'last_name'      => ['nullable', 'string', 'max:255'],
            'designation'    => ['nullable', 'string', 'max:255'],
            'department'     => ['nullable', 'string', 'max:255'],
            // One of the three statuses - a free string let "[object Object]" be
            // stored, which the list showed and no filter matched (R2 BUG-029).
            'job_status'     => ['nullable', 'string', Rule::in(Employee::STATUSES)],
            'joining_date'   => ['nullable', 'date'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'emergency_phone' => ['nullable', 'string', new UsPhoneNumber()],
            'phone_number_2' => ['nullable', 'string', new UsPhoneNumber()],
            // The login's own number, when an account is created with the employee.
            'phone_number'   => ['nullable', 'string', new UsPhoneNumber()],
            'phone_number_1'   => [
                'nullable',
                'string',
                new UsPhoneNumber(),
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
            'city'           => ['nullable', 'string', 'max:255'],
            'state'          => ['nullable', 'string', 'max:255'],
            'postal_code'    => ['nullable', 'string', new UsPostalCode()],
            'country'        => ['required', 'string', 'max:255'],
            'country_code'   => ['nullable'],
            'image'          => ['nullable', SafeUpload::image()],
            'create_account' => ['sometimes', 'boolean'],
            'role_id'        => ['required_if:create_account,1', 'nullable', 'exists:roles,id'],
            'address'        => ['nullable', 'string', 'max:500'],
            'is_paid'        => ['nullable'],
            'months'         => ['nullable', 'integer', 'min:1'],
            'amount'         => ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => __('employee.first_name_required'),
            'job_status.in' => __('employee.job_status_in'),
            'phone_number_1.unique' => __('employee.phone_unique'),
            'email.required' => __('employee.email_required'),
            'email.email' => __('employee.email_valid'),
            'email.unique' => __('employee.email_unique'),
            'country.required' => __('employee.country_required'),
            'role_id.required_if' => __('employee.role_required_if_create_account'),
            'role_id.exists' => __('employee.role_exists'),
        ];
    }
}
