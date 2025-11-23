<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Employee;

use App\Helpers\ApiResponse;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare data before validation.
     */
 protected function prepareForValidation()
    {
        $this->merge([
            'full_name' => $this->first_name,
        ]);
    }

    public function rules(): array
    {
        $employeeId = $this->route('employee'); // Current employee id
        $partnerId = $this->user()?->partner_id;

        return [
            'first_name'     => ['required', 'string', 'max:255'],
            'full_name'      => ['nullable', 'string', 'max:255'],
            'last_name'      => ['nullable', 'string', 'max:255'],
            'designation'    => ['nullable', 'string', 'max:255'],
            'department'     => ['nullable', 'string', 'max:255'],
            'job_status'     => ['nullable', 'string', 'max:255'],
            'joining_date'   => ['nullable', 'date'],
            'identification_number' => ['nullable', 'string', 'max:255'],
            'blood_group'    => ['nullable', 'string', 'max:20'],
            'place_of_birth' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'emergency_phone'   => ['nullable', 'string', 'max:50'],
            'marital_status' => ['nullable', 'string', 'max:50'],
            'no_of_children' => ['nullable', 'integer', 'min:0'],
            'employee_code'  => ['nullable', 'string', 'max:255'],
            'phone_number_1' => [
                'nullable',
                'string',
                'max:20',
                // Rule::unique('employees')
                //     ->ignore($employeeId)
                //     ->where(fn($q) => $q->where('partner_id', $partnerId)),
            ],
            'phone_number_2' => ['nullable', 'string', 'max:20'],
            'passport_number'=> ['nullable', 'string', 'max:255'],
            // 'email' => [
            //     'nullable',
            //     'email',
            //     'max:255',
            //     Rule::unique('employees')
            //         ->ignore($employeeId)
            //         ->where(fn($q) => $q->where('partner_id', $partnerId)),
            // ],
             'email' => [
                'nullable'
            ],
            'date_of_birth'  => ['nullable', 'date', 'before:today'],
            'gender'         => ['nullable', 'string'],
            'city'           => ['nullable', 'string', 'max:255'],
            'state'          => ['nullable', 'string', 'max:255'],
            'postal_code'    => ['nullable', 'string', 'max:50'],
            'country'        => ['nullable', 'string', 'max:255'],
            'ssn'            => ['nullable', 'string', 'max:255'],
            'image'          => ['nullable'],
            'create_account' => ['sometimes', 'boolean'],
            'role_id'        => ['nullable', 'exists:roles,id'],
            'address'        => ['nullable', 'string', 'max:500'],
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
