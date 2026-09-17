<?php

namespace App\Http\Resources\Borrower\PreQualification;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\PreQualificationGptResult;
use App\Models\Borrower\PreQualificationRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PreQualificationResource extends JsonResource
{
    public function toArray($request): array
    {
        $borrower = ($this->resource instanceof \Illuminate\Database\Eloquent\Model && $this->resource->relationLoaded('borrower'))
            ? $this->borrower 
            : (!empty($this->borrower_id) ? Borrower::find($this->borrower_id) : (auth()->check() && auth()->user()->entity_id ? Borrower::find(auth()->user()->entity_id) : null));

        $startDate = $this->business_start_date ?? $borrower?->business_start_date ?? null;
        $yearsInBusiness = !empty($startDate) 
            ? calculateTimeInBusiness($startDate) 
            : ($this->years_in_business ?? ($borrower?->years_in_business ? $borrower->years_in_business . ' Years' : null));

        return [
            'id' => $this->id ?? null,

            // Personal Info
            'first_name' => $this->first_name ?? null,
            'last_name' => $this->last_name ?? null,
            'email' => $this->email ?? null,
            'phone_number' => $this->phone_number ?? null,
            'business_website' => $this->business_website ?? null,
            'state_of_incorporation' => $this->state_of_incorporation ?? null,

            // Business Info
            'business_name' => $this->business_name ?? null,
            'industry_type' => $this->industry_type ?? null,
            'business_structure' => $this->business_structure ?? null,
            'years_in_business' => $yearsInBusiness,
            'monthly_revenue' => !empty($this->monthly_revenue) ? currencyFormat($this->monthly_revenue) : null,
            'annual_revenue' => !empty($this->annual_revenue) ? currencyFormat($this->annual_revenue) : null,

            // Financial Info
            'funding_requested_amount' => !empty($this->funding_requested_amount) ? currencyFormat($this->funding_requested_amount) : null,
            'funding_urgency' => $this->funding_urgency ?? null,
            'purpose_of_funding' => $this->purpose_of_funding ?? null,
            'purpose_note' => $this->purpose_note ?? null,
            'personal_credit_score' => $this->personal_credit_score ?? null,
            'estimated_business_credit_score' => $this->estimated_business_credit_score ?? null,
            'existing_business_debt' => $this->existing_business_debt ?? false,
            'debt_balance' => !empty($this->debt_balance) ? currencyFormat($this->debt_balance) : null,

            //others
            'notes' => $this->notes ?? null,
            'status' => $this->status ?? null,

            // Underwriting outcome. ONLY the client-safe generation is exposed here:
            // the internal memo, risk flags and lender routing must never reach a
            // borrower-facing response.
            'validation_status' => $this->validation_status ?? null,
            'validation_summary' => $this->validation_summary ?? null,
            'gpt_status' => $this->gpt_status ?? null,
            'gpt_generated_at' => !empty($this->gpt_generated_at) ? Carbon::parse($this->gpt_generated_at)->format('Y-m-d h:i A') : null,
            'underwriting_result' => $this->clientAueResult(),
        ];
    }

    /**
     * The borrower-safe AUE output, read from the client results table only.
     */
    protected function clientAueResult(): ?array
    {
        if (empty($this->id)) {
            return null;
        }

        // Only a completed review has a result to show. A re-submitted request
        // keeps the previous run's stored row until the new evaluation replaces
        // it, so while the current review is pending, blocked or failed that row
        // is superseded — returning it would present an old outcome as this one.
        if (($this->gpt_status ?? null) !== PreQualificationRequest::GPT_COMPLETED) {
            return null;
        }

        // client() scope is mandatory here: internal rows live in the same table
        // and carry the underwriting memo, risk flags and lender routing.
        $row = PreQualificationGptResult::query()
            ->forApplication((int) $this->id)
            ->client()
            ->latest('id')
            ->first();

        return is_array($row?->payload) ? $row->payload : null;
    }
}