<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Lead;

use App\Rules\NotFutureDate;
use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;
use App\Rules\UsPostalCode;
use App\Rules\BusinessEin;
use App\Rules\DunsNumber;
use App\Rules\UsPhoneNumber;
use App\Rules\Ssn;

class StoreLeadRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'probability' => ['nullable', 'string', 'max:255'],
            'lead_type' => ['nullable', 'string', 'in:Partner,Borrower'],
            'status' => ['nullable', 'string', 'max:255'],
            'salesperson_id' => ['nullable', 'exists:users,id'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
            'lead_source' => ['nullable', 'string', 'max:255'],
            'employees' => ['nullable', 'string', 'max:255'],

            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone_number' => [
                'nullable',
                'string',
                new UsPhoneNumber(),
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('leads', 'email')->whereNull('deleted_at'),
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            'legal_business_name' => ['nullable', 'string', 'max:255'],
            'business_ein' => ['nullable', 'string', new BusinessEin()],
            'state_file_number' => ['nullable', 'string', 'max:255'],
            'duns_number' => ['nullable', 'string', new DunsNumber()],
            'business_address' => ['nullable', 'string', 'max:500'],
            'country' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'other_city' => ['required_if:city,Others', 'nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'zip_code' => ['nullable', 'string', new UsPostalCode()],
            'business_phone' => ['nullable', 'string', new UsPhoneNumber()],
            'entity_type' => ['nullable', 'string', 'max:255'],
            'entity_type_note' => ['nullable', 'string'],
            'industry_type' => ['nullable', 'string', 'max:255'],
            'industry_type_note' => ['nullable', 'string'],
            'business_start_date' => ['nullable', 'date', new NotFutureDate()], // BUG-007
            // The full SSN, kept encrypted (Lead::ssn), as on a client's profile.
            'ssn' => ['nullable', 'string', new Ssn()],
            'requirement_details' => ['nullable', 'string'],
            'lead_source_note' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation()
    {
        // A written EIN (12-3456789) is kept as its nine digits.
        if ($this->has('business_ein')) {
            $this->merge(['business_ein' => BusinessEin::canonical($this->input('business_ein'))]);
        }

        // A written SSN (123-45-6789) is kept as its nine digits.
        if ($this->has('ssn')) {
            $this->merge(['ssn' => Ssn::canonical($this->input('ssn'))]);
        }

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

    public function validated($key = null, $default = null): array
    {
        $data = parent::validated($key, $default);

        if (($data['city'] ?? '') === 'Others' && !empty($data['other_city'])) {
            $data['city'] = $data['other_city'];
        }

        unset($data['other_city']);

        return $data;
    }

    public function messages(): array
    {
        return [
            'email.unique' => __('lead.email_taken'),
            'other_city.required_if' => __('lead.other_city_required'),
            'phone_number.regex' => __('lead.phone_number_format_invalid'),
        ];
    }
}
