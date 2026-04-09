<?php

namespace App\Http\Resources\Borrower\CreditBuilder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "card_name" => $this->card_name,
            "issuer" => $this->issuer,
            "credit_category" => $this->credit_category,
            "difficulty_rank" => $this->difficulty_rank,
            "difficulty_tier" => $this->difficulty_tier,
            "engine_eligibility_band" => $this->engine_eligibility_band,
            "approval_level" => $this->approval_level,
            "typical_personal_fico_min" => $this->typical_personal_fico_min,
            "typical_personal_fico_ideal" => $this->typical_personal_fico_ideal,
            "time_in_business_min_months" => $this->time_in_business_min_months,
            "revenue_expectation" => $this->revenue_expectation,
            "business_credit_required" => $this->business_credit_required,
            "pg_required" => $this->pg_required,
            "cash_based" => $this->cash_based,
            "velocity_sensitive" => $this->velocity_sensitive,
            "relationship_required" => $this->relationship_required,
            "portal_tier_unlock" => $this->portal_tier_unlock,
            "borrower_exposure" => $this->borrower_exposure,
            "sequencing_priority" => $this->sequencing_priority,
            "image_url" => $this->image_url ? asset("storage/" . $this->image_url) : asset("storage/credit-accounts/default.png"),
            "reference_url" => $this->reference_url,
            "status" => $this->applications()
                ->where("borrower_id", auth()->user()?->entity_id)
                ->first()?->status,
        ];
    }
}