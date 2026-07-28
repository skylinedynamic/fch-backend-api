<?php

namespace App\Http\Requests\Api\V1\Borrower\FundApplication;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;

class FederalComplianceRequest extends BaseApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id' => 'bail|nullable|exists:funding_applications,id',
            'filed_for_bankruptcy' => 'bail|required|boolean',
            'any_pending_lawsuits' => 'bail|required|boolean',
            'has_federal_loan' => 'bail|required|boolean',
            'has_federal_debt' => 'bail|required|boolean',
            'has_criminal_offense' => 'bail|required|boolean',
            'has_parole_or_probation' => 'bail|required|boolean',
            'suspended_or_debarred' => 'bail|required|boolean',
            'any_outstanding_judgments_business' => 'bail|required|boolean',
            'any_outstanding_judgments_owner' => 'bail|required|boolean',
            'federal_compliance_disclosures_explanation' => [
                'bail',
                Rule::requiredIf(fn () =>
                    $this->filed_for_bankruptcy ||
                    $this->any_pending_lawsuits ||
                    $this->has_federal_loan ||
                    $this->has_federal_debt ||
                    $this->has_criminal_offense ||
                    $this->has_parole_or_probation ||
                    $this->suspended_or_debarred ||
                    $this->any_outstanding_judgments_business ||
                    $this->any_outstanding_judgments_owner
                ),
                'nullable',
                'string'
            ],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.exists' => __('borrower_validation.funding_application_id_exists'),
            'filed_for_bankruptcy.boolean' => __('borrower_validation.filed_for_bankruptcy_boolean'),
            'any_pending_lawsuits.boolean' => __('borrower_validation.any_pending_lawsuits_boolean'),
            'has_federal_loan.boolean' => __('borrower_validation.has_federal_loan_boolean'),
            'has_federal_debt.boolean' => __('borrower_validation.has_federal_debt_boolean'),
            'has_criminal_offense.boolean' => __('borrower_validation.has_criminal_offense_boolean'),
            'has_parole_or_probation.boolean' => __('borrower_validation.has_parole_or_probation_boolean'),
            'suspended_or_debarred.boolean' => __('borrower_validation.suspended_or_debarred_boolean'),
            'any_outstanding_judgments_business.boolean' => __('borrower_validation.any_outstanding_judgments_business_boolean'),
            'any_outstanding_judgments_owner.boolean' => __('borrower_validation.any_outstanding_judgments_owner_boolean'),
            'federal_compliance_disclosures_explanation.string' => __('borrower_validation.federal_compliance_disclosures_explanation_string'),
            'federal_compliance_disclosures_explanation.max' => __('borrower_validation.federal_compliance_disclosures_explanation_max'),
        ];
    }
}
