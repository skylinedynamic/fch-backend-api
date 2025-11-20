<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Employee;

use App\Helpers\ApiResponse;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreEmployeeRequest extends FormRequest
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
        // If you want full_name = first_name only
        $this->merge([
            'full_name' => $this->first_name, 
        ]);

        // If you want full_name = first_name + last_name
        // $this->merge([
        //     'full_name' => trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')),
        // ]);
    }

    public function rules(): array
    {
        $partnerId = $this->user()?->partner_id;

        return [
            'first_name'     => ['required', 'string', 'max:255'],
            'full_name'      => ['nullable', 'string', 'max:255'], // <-- add this rule
            'last_name'      => ['nullable', 'string', 'max:255'],
            'designation'    => ['nullable', 'string', 'max:255'],
            'department'     => ['nullable', 'string', 'max:255'],
            'job_status'     => ['nullable', 'string', 'max:255'],
            'joining_date'   => ['nullable', 'date'],
            'identification_number' => ['nullable', 'string', 'max:255'],
            'blood_group'    => ['nullable', 'string', 'max:20'],
            'place_of_birth' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'emergency_phone' => ['nullable', 'string', 'max:50'],
            'marital_status' => ['nullable', 'string', 'max:50'],
            'no_of_children' => ['nullable', 'integer', 'min:0'],
            'employee_code' => ['nullable'],
            'phone_number_1'   => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('employees')->where(fn($q) => $q->where('partner_id', $partnerId)),
            ],
            'email' => [
                'required_if:create_account,true',
                'nullable',
                'email',
                'max:255',
                Rule::unique('employees')->where(fn($q) => $q->where('partner_id', $partnerId)),
            ],
            'date_of_birth'  => ['nullable', 'date', 'before:today'],
            'gender'         => ['nullable', 'in:male,female,other'],
            'city'           => ['nullable', 'string', 'max:255'],
            'state'          => ['nullable', 'string', 'max:255'],
            'postal_code'    => ['nullable', 'string', 'max:50'],
            'country'        => ['nullable', 'string', 'max:255'],
            'ssn'            => ['nullable', 'string', 'max:255'],
            'image'          => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'create_account' => ['sometimes', 'boolean'],
            'role_id'        => ['required_if:create_account,true', 'exists:roles,id'],
            'address'        => ['nullable'],
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
