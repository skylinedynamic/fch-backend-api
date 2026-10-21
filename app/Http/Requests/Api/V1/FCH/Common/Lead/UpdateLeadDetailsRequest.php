<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Lead;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Http\Requests\Api\V1\FCH\Common\Lead\Concerns\LeadPartnerRules;
use App\Models\Lead\Lead;

class UpdateLeadDetailsRequest extends BaseApiRequest
{
    use LeadPartnerRules;

    private ?Lead $lead = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->leadPartnerRules($this->lead()?->partner ?? $this->user()?->partner, $this->lead()?->salesperson_id, $this->lockedType()),
            'probability' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            'contact_person' => ['required', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
            'lead_source' => ['nullable', 'string', 'max:255'],
            'lead_source_note' => ['required_if:lead_source,Other', 'string', 'max:255', 'nullable'],
            'referral_partner_id' => ['required_if:lead_source,Referral Partner', 'nullable', 'exists:contacts,id'],
            'requirement_details' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->leadPartnerMessages($this->lead()?->partner ?? $this->user()?->partner, $this->lockedType()),
            'probability.required' => __('lead.probability_required'),
            'lead_type.required' => __('lead.lead_type_required'),
            'status.required' => __('lead.status_required'),
            'company_name.required' => __('lead.company_name_required'),
            'contact_person.required' => __('lead.contact_person_required'),
            'lead_source_note.required_if' => __('lead.lead_source_note_required'),
            'referral_partner_id.required_if' => __('lead.referral_partner_required'),
        ];
    }

    /** The lead being updated - its partner decides the rules. */
    private function lead(): ?Lead
    {
        return $this->lead ??= Lead::find($this->route('id'));
    }

    /** A converted lead's type, which it keeps; null while it may still change. */
    private function lockedType(): ?string
    {
        return $this->lead()?->convert_to ? $this->lead()->lead_type : null;
    }
}
