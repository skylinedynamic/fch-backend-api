<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Lead;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UpdateLeadDetailsRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'probability' => ['required', 'string', 'max:255'],
            'lead_type' => ['required', 'string', 'in:Partner,Borrower'],
            'status' => ['required', 'string', 'max:255'],
            'salesperson_id' => ['nullable', 'exists:users,id'],
            'company_name' => ['required', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
            'lead_source' => ['required', 'string', 'max:255'],
            'lead_source_note' => ['required_if:lead_source,Other', 'string', 'max:255', 'nullable'],
            'requirement_details' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'probability.required' => __('lead.probability_required'),
            'lead_type.required' => __('lead.lead_type_required'),
            'status.required' => __('lead.status_required'),
            'company_name.required' => __('lead.company_name_required'),
            'lead_source.required' => __('lead.lead_source_required'),
            'lead_source_note.required_if' => __('lead.lead_source_note_required'),
        ];
    }
}
