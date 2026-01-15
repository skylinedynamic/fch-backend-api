<?php

namespace App\Http\Resources\Borrower\FundApplication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FederalComplianceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'filed_for_bankruptcy' => false,
            'any_pending_lawsuits' => true,
            'has_federal_loan' => false,
            'has_federal_debt' => true,
            'has_criminal_offense' => false,
            'has_parole_or_probation' => false,
            'suspended_or_debarred' => true,
            'any_outstanding_judgments_business' => true,
            'any_outstanding_judgments_owner' => false,
            'federal_compliance_disclosures_explanation' => null,
        ];
    }
}
