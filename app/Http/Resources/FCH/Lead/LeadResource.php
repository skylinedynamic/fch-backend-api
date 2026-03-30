<?php

namespace App\Http\Resources\FCH\Lead;

use App\Http\Resources\FCH\Partner\PartnerResource;
use App\Http\Resources\FCH\User\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Lead\Lead */
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
            'ssn_last_4' => $this->ssn_last_4,
            'requirement_details' => $this->requirement_details,
            'convert_to' => $this->convert_to,
            'convert_email_already' => $this->email ? (
                \App\Models\Borrower\Borrower::where('email', $this->email)->exists() ? 'Borrower' : (
                    \App\Models\Partner\Partner::where('email', $this->email)->exists() ? 'Partner' : false
                )
            ) : false,

            // Partner Details
            'partner' => [
                'id' => $this->partner_id,
                'full_name' => $this->partner?->full_name,
            ],

            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
