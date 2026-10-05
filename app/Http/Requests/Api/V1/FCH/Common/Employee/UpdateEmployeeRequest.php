<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Employee;

use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Employee\Employee;
use App\Models\User\User;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Rules\UsPostalCode;

class UpdateEmployeeRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $employeeId = $this->route('employee'); // Current employee id
        $partnerId = $this->user()?->partner_id;

        // The employee's email is also its login email when it has (or is
        // getting) an account, and users.email is unique across every tenant —
        // so a clash has to fail validation here instead of crashing the save.
        // Matches the DB index exactly: soft-deleted users still hold theirs.
        $linkedUserId = User::where('entity_type', Employee::class)->where('entity_id', $employeeId)->value('id');
        $emailRules = ['nullable', 'email', 'max:255'];
        if ($linkedUserId || $this->boolean('create_account')) {
            $emailRules[] = Rule::unique('users', 'email')->ignore($linkedUserId);
        }

        return [
            'first_name'     => ['sometimes', 'required', 'string', 'max:255'],
            'last_name'      => ['nullable', 'string', 'max:255'],
            'designation'    => ['sometimes', 'required', 'string', 'max:255'],
            'department'     => ['sometimes', 'required', 'string', 'max:255'],
            'job_status'     => ['nullable', 'string', 'max:255'],
            'joining_date'   => ['nullable', 'date'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'emergency_phone'   => ['nullable', 'string', 'max:50'],
            'phone_number_1' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('employees')
                    ->ignore($employeeId)
                    ->where(fn($q) => $q->where('partner_id', $partnerId)->whereNull('deleted_at')),
            ],
            'phone_number_2' => ['nullable', 'string', 'max:20'],
            'passport_number'=> ['nullable', 'string', 'max:255'],
            // Not required on update; unique only where it doubles as a login (above).
            'email' => $emailRules,
            'date_of_birth'  => ['nullable', 'date', 'before:today'],
            'gender'         => ['nullable', 'string'],
            'city'           => ['sometimes', 'required', 'string', 'max:255'],
            'state'          => ['sometimes', 'required', 'string', 'max:255'],
            'postal_code'    => ['nullable', 'string', new UsPostalCode()],
            'country'        => ['sometimes', 'required', 'string', 'max:255'],
            'country_code'   => ['nullable'],
            'image'          => ['nullable'],
            'create_account' => ['sometimes', 'boolean'],
            'role_id'        => ['required_if:create_account,1', 'nullable', 'exists:roles,id'],
            'address'        => ['sometimes', 'required', 'string', 'max:500'],
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
