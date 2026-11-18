<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Lead;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Http\Requests\Api\V1\FCH\Common\Lead\Concerns\LeadPartnerRules;
use Illuminate\Validation\Rule;

class StoreLeadDetailsRequest extends BaseApiRequest
{
    use LeadPartnerRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A new lead is filed under the creator's partner (LeadService::storeDetails)
            ...$this->leadPartnerRules($this->user()?->partner),
            'probability' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            'contact_person' => ['required', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
            'lead_source' => ['nullable', 'string', 'max:255'],
            'lead_source_note' => ['required_if:lead_source,Other', 'string', 'max:255', 'nullable'],
            // Not a deleted contact (R3-BUG-017).
            'referral_partner_id' => ['required_if:lead_source,Referral Partner', 'nullable', Rule::exists('contacts', 'id')->whereNull('deleted_at')],
            'requirement_details' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->leadPartnerMessages($this->user()?->partner),
            'probability.required' => __('lead.probability_required'),
            'lead_type.required' => __('lead.lead_type_required'),
            'status.required' => __('lead.status_required'),
            'company_name.required' => __('lead.company_name_required'),
            'contact_person.required' => __('lead.contact_person_required'),
            'lead_source_note.required_if' => __('lead.lead_source_note_required'),
            'referral_partner_id.required_if' => __('lead.referral_partner_required'),
        ];
    }
}
