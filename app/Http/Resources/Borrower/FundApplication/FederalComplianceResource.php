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
            'id' => isset($this->borrower_id) ? $this->id : null,
            'filed_for_bankruptcy' => $this->filed_for_bankruptcy === null ? null : $this->filed_for_bankruptcy,
            'any_pending_lawsuits' => $this->any_pending_lawsuits === null ? null : $this->any_pending_lawsuits,
            'has_federal_loan' => $this->has_federal_loan === null ? null : $this->has_federal_loan,
            'has_federal_debt' => $this->has_federal_debt === null ? null : $this->has_federal_debt,
            'has_criminal_offense' => $this->has_criminal_offense === null ? null : $this->has_criminal_offense,
            'has_parole_or_probation' => $this->has_parole_or_probation === null ? null : $this->has_parole_or_probation,
            'suspended_or_debarred' => $this->suspended_or_debarred === null ? null : $this->suspended_or_debarred,
            'any_outstanding_judgments_business' => $this->any_outstanding_judgments_business === null ? null : $this->any_outstanding_judgments_business,
            'any_outstanding_judgments_owner' => $this->any_outstanding_judgments_owner === null ? null : $this->any_outstanding_judgments_owner,
            'federal_compliance_disclosures_explanation' => $this->federal_compliance_disclosures_explanation === null ? null : $this->federal_compliance_disclosures_explanation,
        ];
    }
}
