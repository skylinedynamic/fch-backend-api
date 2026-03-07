<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Lead;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;

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
            'phone_number' => ['required', 'string', 'max:20', 'regex:/^[\d\s\(\)\-\+]+$/'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('leads', 'email')->ignore($this->route('id'))->whereNull('deleted_at'),
                Rule::unique('users', 'email')->ignore(
                    \App\Models\User\User::where('email', \App\Models\Lead\Lead::where('id', $this->route('id'))->value('email'))
                        ->value('id')
                )->whereNull('deleted_at'),
            ],
            'legal_business_name' => ['nullable', 'string', 'max:255'],
            'business_ein' => ['nullable', 'string', 'max:20'],
            'state_file_number' => ['nullable', 'string', 'max:255'],
            'duns_number' => ['nullable', 'string', 'max:20'],
            'business_address' => ['nullable', 'string', 'max:500'],
            'country' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'other_city' => ['required_if:city,Others', 'nullable', 'string', 'max:255'],
            'state' => ['required', 'string', 'max:255'],
            'zip_code' => ['nullable', 'string', 'max:20'],
            'business_phone' => ['nullable', 'string', 'max:20'],
            'entity_type' => ['nullable', 'string', 'max:255'],
            'entity_type_note' => ['required_if:entity_type,Other / Unknown', 'nullable', 'string'],
            'industry_type' => ['nullable', 'string', 'max:255'],
            'industry_type_note' => ['required_if:industry_type,Other / Unknown', 'nullable', 'string'],
            'business_start_date' => ['nullable'],
            'ssn_last_4' => ['nullable', 'string', 'max:4'],
        ];
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
            'phone_number.regex' => __('lead.phone_number_regex_invalid'),
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
