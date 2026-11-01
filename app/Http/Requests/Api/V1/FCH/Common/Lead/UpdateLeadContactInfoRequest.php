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

class UpdateLeadContactInfoRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contact_person' => ['required', 'string', 'max:255'],
            'phone_number' => [
                'required',
                'string',
                new UsPhoneNumber(),
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore(
                    \App\Models\User\User::where('email', \App\Models\Lead\Lead::where('id', $this->route('id'))->value('email'))
                        ->value('id')
                )->whereNull('deleted_at'),
            ],
            'legal_business_name' => ['nullable', 'string', 'max:255'],
            'business_ein' => ['nullable', 'string', new BusinessEin()],
            'state_file_number' => ['nullable', 'string', 'max:255'],
            'duns_number' => ['nullable', 'string', new DunsNumber()],
            'business_address' => ['nullable', 'string', 'max:500'],
            'country' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'other_city' => ['required_if:city,Others', 'nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'zip_code' => ['nullable', 'string', new UsPostalCode()],
            'business_phone' => ['nullable', 'string', new UsPhoneNumber()],
            'entity_type' => ['nullable', 'string', 'max:255'],
            'entity_type_note' => ['required_if:entity_type,Other,Other / Unknown', 'nullable', 'string'],
            'industry_type' => ['nullable', 'string', 'max:255'],
            'industry_type_note' => ['required_if:industry_type,Other,Other / Unknown', 'nullable', 'string'],
            'business_start_date' => ['nullable', new NotFutureDate()], // BUG-007
            // The full SSN, kept encrypted (Lead::ssn), as on a client's profile.
            'ssn' => ['nullable', 'string', new Ssn()],
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
            'contact_person.required' => __('lead.contact_person_required'),
            'phone_number.required' => __('lead.phone_number_required'),
            'phone_number.regex' => __('lead.phone_number_format_invalid'),
            'email.required' => __('lead.email_required'),
            'email.unique' => __('lead.email_taken'),
            'country.required' => __('lead.country_required'),
            'city.required' => __('lead.city_required'),
            'state.required' => __('lead.state_required'),
            'other_city.required_if' => __('lead.other_city_required'),
            'entity_type_note.required_if' => __('lead.entity_type_note_required'),
            'industry_type_note.required_if' => __('lead.industry_type_note_required'),
        ];
    }
}
