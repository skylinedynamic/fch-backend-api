<?php

namespace App\Http\Requests\Api\V1\Global\Website\Lead;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contact_person' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255'
            ],
            'phone_number' => [
                'nullable',
                'string',
                'regex:/^(\(\d{3}\) \d{3}-\d{4}|\d{10})$/',
            ],
            'company_name' => ['nullable', 'string', 'max:255'],
            'requirements_details' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation()
    {
        if ($this->phone_number) {
            $phoneNumber = preg_replace('/\D/', '', $this->phone_number);
            
            if (strlen($phoneNumber) === 10) {
                $this->merge([
                    'phone_number' => sprintf(
                        '(%s) %s-%s',
                        substr($phoneNumber, 0, 3),
                        substr($phoneNumber, 3, 3),
                        substr($phoneNumber, 6)
                    ),
                ]);
            }
        }
    }

    public function messages(): array
    {
        return [
            'email.unique' => __('lead.email_taken'),
            'contact_person.required' => __('lead.contact_person_website_required'),
            'email.required' => __('lead.email_website_required'),
            'phone_number.regex' => __('lead.phone_number_format_invalid'),
        ];
    }
}
