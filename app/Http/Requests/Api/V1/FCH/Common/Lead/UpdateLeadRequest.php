<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Lead;

use App\Rules\NotFutureDate;
use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;
use App\Rules\UsPostalCode;
use App\Rules\UsPhoneNumber;
use App\Rules\Ssn;
use App\Rules\BusinessEin;
use App\Rules\DunsNumber;

class UpdateLeadRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('lead');

        return [
            'probability' => ['nullable', 'string', 'max:255'],
            // 'lead_type' => ['nullable', 'string', 'in:Partner,Borrower'],
            'status' => ['nullable', 'string', 'max:255'],
            'salesperson_id' => ['nullable', 'exists:users,id'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
            'lead_source' => ['nullable', 'string', 'max:255'],
            'employees' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', new UsPhoneNumber()],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('leads', 'email')->ignore($this->route('lead'))->whereNull('deleted_at'),
                Rule::unique('users', 'email')->ignore(
                    \App\Models\User\User::where('entity_type', \App\Models\Lead\Lead::class)
                        ->where('entity_id', (int) $this->route('lead'))
                        ->value('id')
                )->whereNull('deleted_at'),
            ],
            'legal_business_name' => ['nullable', 'string', 'max:255'],
            'business_ein' => ['nullable', 'string', new BusinessEin()],
            'state_file_number' => ['nullable', 'string', 'max:255'],
            'duns_number' => ['nullable', 'string', new DunsNumber()],
            'business_address' => ['nullable', 'string', 'max:500'],
            'country' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
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

    protected function prepareForValidation(): void
    {
        // A written EIN (12-3456789) is kept as its nine digits.
        if ($this->has('business_ein')) {
            $this->merge(['business_ein' => BusinessEin::canonical($this->input('business_ein'))]);
        }

        // A written SSN (123-45-6789) is kept as its nine digits.
        if ($this->has('ssn')) {
            $this->merge(['ssn' => Ssn::canonical($this->input('ssn'))]);
        }
    }

    public function messages(): array
    {
        return [
            'email.unique' => __('lead.email_taken'),
        ];
    }
}
