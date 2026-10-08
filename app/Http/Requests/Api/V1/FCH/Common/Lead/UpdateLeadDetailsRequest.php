<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Lead;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;

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
            'contact_person' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('leads', 'email')->ignore($this->route('id'))->whereNull('deleted_at'),
                Rule::unique('users', 'email')->ignore(
                    \App\Models\User\User::where('entity_type', \App\Models\Lead\Lead::class)
                        ->where('entity_id', (int) $this->route('id'))
                        ->value('id')
                )->whereNull('deleted_at'),
            ],
            'domain' => ['nullable', 'string', 'max:255'],
            'lead_source' => ['required', 'string', 'max:255'],
            'lead_source_note' => ['required_if:lead_source,Other', 'string', 'max:255', 'nullable'],
            'referral_partner_id' => ['required_if:lead_source,Referral Partner', 'nullable', 'exists:contacts,id'],
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
            'contact_person.required' => __('lead.contact_person_required'),
            'email.required' => __('lead.email_required'),
            'email.unique' => __('lead.email_taken'),
            'lead_source.required' => __('lead.lead_source_required'),
            'lead_source_note.required_if' => __('lead.lead_source_note_required'),
            'referral_partner_id.required_if' => __('lead.referral_partner_required'),
        ];
    }
}
