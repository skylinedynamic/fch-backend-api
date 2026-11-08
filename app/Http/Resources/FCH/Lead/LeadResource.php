<?php

namespace App\Http\Resources\FCH\Lead;

use App\Http\Resources\FCH\User\UserResource;
use App\Models\Borrower\Borrower;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lead */
class LeadResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {

        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'tasks_no' => $this->tasks_no,
            'opportunity' => $this->tasks_no,
            'probability' => $this->probability,
            'lead_type' => $this->lead_type,
            'status' => $this->status,
            // 'salesperson' => new UserResource($this->salesperson),
            'salesperson_name' => $this->salesperson?->name,
            'salesperson_id' => $this->salesperson?->id,
            'company_name' => $this->company_name,
            'domain' => $this->domain,
            'lead_source' => $this->lead_source,
            'lead_source_note' => $this->lead_source_note,
            'referral_partner_id' => $this->referral_partner_id,
            'employees' => $this->employees,

            // Contact Info
            'contact_person' => $this->contact_person,
            'contact_name' => $this->contact_person,
            'phone_number' => $this->phone_number,
            'email' => $this->email,
            'legal_business_name' => $this->legal_business_name,
            'business_ein' => $this->business_ein,
            'state_file_number' => $this->state_file_number,
            'duns_number' => $this->duns_number,
            'business_address' => $this->business_address,
            'country' => $this->country,
            'state' => $this->state,
            'city' => $this->city,
            'zip_code' => $this->zip_code,
            'business_phone' => $this->business_phone,
            'entity_type' => $this->entity_type,
            'entity_type_note' => $this->entity_type_note,
            'industry_type' => $this->industry_type,
            'industry_type_note' => $this->industry_type_note,
            'business_start_date' => $this->business_start_date?->format('Y-m-d'),
            // The full SSN, for the partner's own lead screen - as a client's profile reads it.
            'ssn' => $this->ssn,
            'requirement_details' => $this->requirement_details,
            'convert_to' => $this->convert_to,
            'convert_email_already' => $this->emailAlreadyIn(),

            // Partner Details
            'partner' => [
                'id' => $this->partner_id,
                'full_name' => $this->partner?->full_name,
                'company_name' => $this->partner?->company_name,
            ],

            // The partner the host converts another partner's lead for
            'convert_on_behalf_of' => $this->convertsOnBehalfOfPartnerFor($request->user())
                ? ['id' => $this->partner_id, 'company_name' => $this->partner?->company_name]
                : null,

            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Whether this lead's email is already one of its own partner's clients
     * ('Borrower') or - for the host, whose book partners are - a partner
     * ('Partner'). Only the lead's own partner's records count (BUG-018): an
     * email that is another partner's client must look like any other lead,
     * and converting it gets the neutral message (LeadService::convertToBorrower).
     */
    private function emailAlreadyIn(): string|false
    {
        if (! $this->email) {
            return false;
        }

        if (Borrower::where('email', $this->email)->where('partner_id', $this->partner_id)->exists()) {
            return 'Borrower';
        }

        if ($this->partner?->is_host && Partner::where('email', $this->email)->exists()) {
            return 'Partner';
        }

        return false;
    }
}
