<?php

namespace App\Http\Resources\FCH\EmbedForm;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\EmbedForm\EmbedFormSubmission */
class EmbedFormSubmissionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'submission_uid' => $this->submission_uid,
            'partner_id' => $this->partner_id,
            'form_config_id' => $this->form_config_id,

            // Personal & Funding Information
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => "{$this->first_name} {$this->last_name}",
            'email' => $this->email,
            'phone' => $this->phone,
            'birthday' => $this->birthday,
            'credit_score' => $this->credit_score,
            'desired_funding_amount' => $this->desired_funding_amount,

            // Living & Housing Information
            'home_address' => $this->home_address,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'monthly_housing_payment' => $this->monthly_housing_payment,
            'housing_status' => $this->housing_status,

            // Employment Information
            'employer_name' => $this->employer_name,
            'employer_phone' => $this->employer_phone,
            'employer_address' => $this->employer_address,
            'occupation' => $this->occupation,
            'annual_income' => $this->annual_income,

            // Referral & Audit Information
            'sales_rep' => $this->sales_rep,
            'referral_source' => $this->referral_source,
            'status' => $this->status,
            'ip_address' => $this->ip_address,
            'origin_url' => $this->origin_url,

            // Partner details
            'partner' => $this->whenLoaded('partner', function () {
                return [
                    'id' => $this->partner?->id,
                    'full_name' => $this->partner?->full_name,
                    'company_name' => $this->partner?->company_name,
                ];
            }),

            // Attachments
            'attachments' => EmbedFormAttachmentResource::collection($this->whenLoaded('attachments')),

            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
