<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Lead;

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
            'probability' => ['nullable', 'string', 'max:255'],
            'lead_type' => ['nullable', 'string', 'in:Partner,Borrower'],
            'status' => ['nullable', 'string', 'max:255'],
            'salesperson_id' => ['nullable', 'exists:users,id'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
            'lead_source' => ['nullable', 'string', 'max:255'],
            'employees' => ['nullable', 'string', 'max:255'],

            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'email' => [
                'nullable',
                'email',
                'max:255'
            ],
            'legal_business_name' => ['nullable', 'string', 'max:255'],
            'business_ein' => ['nullable', 'string', 'max:20'],
            'state_file_number' => ['nullable', 'string', 'max:255'],
            'duns_number' => ['nullable', 'string', 'max:20'],
            'business_address' => ['nullable', 'string', 'max:500'],
            'country' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'zip_code' => ['nullable', 'string', 'max:20'],
            'business_phone' => ['nullable', 'string', 'max:20'],
            'entity_type' => ['nullable', 'string', 'max:255'],
            'entity_type_note' => ['nullable', 'string'],
            'industry_type' => ['nullable', 'string', 'max:255'],
            'industry_type_note' => ['nullable', 'string'],
            'business_start_date' => ['nullable', 'date'],
            'ssn_last_4' => ['nullable', 'string', 'max:4'],
            'requirement_details' => ['nullable', 'string'],
            'lead_source_note' => ['nullable', 'string'],
        ];
    }
}
