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
use App\Rules\DateOfBirth;
use App\Rules\UsPostalCode;
use App\Rules\UsPhoneNumber;
use App\Rules\AssignableRole;
use App\Rules\SafeUpload;

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
        $emailRules = ['sometimes', 'required', 'email', 'max:255'];
        if ($linkedUserId || $this->boolean('create_account')) {
            $emailRules[] = Rule::unique('users', 'email')->ignore($linkedUserId);
        }

        // As on create, only the name, email and country are required (and may
        // not be cleared); every other detail may be left empty.
        return [
            'first_name'     => ['sometimes', 'required', 'string', 'max:255'],
            'last_name'      => ['nullable', 'string', 'max:255'],
            'designation'    => ['nullable', 'string', 'max:255'],
            'department'     => ['nullable', 'string', 'max:255'],
            // One of the three statuses - a free string let "[object Object]" be
            // stored, which the list showed and no filter matched (R2 BUG-029).
            'job_status'     => ['nullable', 'string', Rule::in(Employee::STATUSES)],
            'joining_date'   => ['nullable', 'date'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'emergency_phone'   => ['nullable', 'string', new UsPhoneNumber()],
            'phone_number_1' => [
                'nullable',
                'string',
                new UsPhoneNumber(),
                Rule::unique('employees')
                    ->ignore($employeeId)
                    ->where(fn($q) => $q->where('partner_id', $partnerId)->whereNull('deleted_at')),
            ],
            'phone_number_2' => ['nullable', 'string', new UsPhoneNumber()],
            'passport_number'=> ['nullable', 'string', 'max:255'],
            // Required, but unique only where it doubles as a login (above).
            'email' => $emailRules,
            'date_of_birth'  => ['nullable', new DateOfBirth()],
            'gender'         => ['nullable', 'string'],
            'city'           => ['nullable', 'string', 'max:255'],
            'state'          => ['nullable', 'string', 'max:255'],
            'postal_code'    => ['nullable', 'string', new UsPostalCode()],
            'country'        => ['sometimes', 'required', 'string', 'max:255'],
            'country_code'   => ['nullable'],
            'image'          => ['nullable', SafeUpload::image()],
            'create_account' => ['sometimes', 'boolean'],
            'role_id'        => ['required_if:create_account,1', 'nullable', new AssignableRole($this->user(), __('employee.role_exists'))],
            'address'        => ['nullable', 'string', 'max:500'],
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
