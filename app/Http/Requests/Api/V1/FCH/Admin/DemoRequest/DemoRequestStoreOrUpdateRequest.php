<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\DemoRequest;

use App\Helpers\ApiResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Exceptions\HttpResponseException;

class DemoRequestStoreOrUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'id' => ['sometimes', 'integer', Rule::exists('demo_requests', 'id')],

            'customer_name' => ['required', 'string', 'max:191'],
            'full_name'     => ['nullable', 'string', 'max:191'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'email'         => ['nullable', 'email', 'max:191'],
            'state'         => ['nullable', 'string', 'max:100'],
            'country'       => ['nullable', 'string', 'max:100'],
            'zip_code'      => ['nullable', 'string', 'max:20'],
            'joining_date'  => ['nullable', 'date'],
            'tax_id'        => ['nullable', 'string', 'max:100'],
            'website'       => ['nullable', 'url', 'max:191'],

            'assigned_to'   => ['nullable', 'integer', Rule::exists('users', 'id')],
            'assigned_email'=> ['nullable', 'email', 'max:191'],
            'assigned_phone'=> ['nullable', 'string', 'max:50'],

            'demo_start'    => ['nullable', 'date'],
            'demo_end'      => ['nullable', 'date', 'after_or_equal:demo_start'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'status'        => ['nullable', 'string', Rule::in(['pending', 'active', 'expired', 'declined'])],

            'created_by'    => ['nullable', 'integer', Rule::exists('users', 'id')],

            'appointment.title'        => ['nullable', 'string', 'max:191'],
            'appointment.event_date'   => ['nullable', 'date'],
            'appointment.start_time'   => ['nullable', 'date_format:H:i'],
            'appointment.end_time'     => ['nullable', 'date_format:H:i', 'after:appointment.start_time'],
            'appointment.time_zone'    => ['nullable', 'string', 'max:100'],
            'appointment.description'  => ['nullable', 'string', 'max:500'],
        ];

        if ($this->isMethod('patch') || $this->isMethod('put')) {
            $rules['customer_name'] = ['sometimes', 'string', 'max:191'];
        }

        return $rules;
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
