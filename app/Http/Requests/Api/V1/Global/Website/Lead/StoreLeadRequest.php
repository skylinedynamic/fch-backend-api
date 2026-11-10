<?php

namespace App\Http\Requests\Api\V1\Global\Website\Lead;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Lead\Lead;
use App\Rules\ReCaptcha;
use Illuminate\Validation\Rule;
use App\Rules\UsPhoneNumber;

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
                new UsPhoneNumber(),
            ],
            'company_name' => ['nullable', 'string', 'max:255'],
            'requirements_details' => ['nullable', 'string'],
            // "What brings you here today?" on the contact page; decides the
            // lead type. Optional: the home page form does not ask it.
            'interest' => ['nullable', 'string', Rule::in(Lead::WEBSITE_INTERESTS)],
            // The contact page runs reCAPTCHA v3 as "contact_form" (findings D5/O29).
            // Required once a secret is configured; until then leads keep coming in.
            'recaptcha_token' => [
                Rule::requiredIf(fn () => ReCaptcha::secret() !== null),
                'nullable',
                'string',
                new ReCaptcha('contact_form', skipWhenUnconfigured: true),
            ],
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
            'recaptcha_token.required' => __('auth.recaptcha_required'),
        ];
    }
}
