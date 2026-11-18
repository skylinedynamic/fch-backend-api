<?php

namespace App\Http\Requests\Api\V1\FCH\Partner\FundApplication;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Rules\MoneyAmount;
use App\Http\Requests\Api\V1\Concerns\FundingRequestStepRules;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Rules\KnownFundingType;
use App\Rules\VisibleToUser;

class StoreFundingRequestRequest extends BaseApiRequest
{
    use FundingRequestStepRules;

    /** The application this save is for, when the caller may reach it. */
    protected function targetApplication(): ?FundingApplication
    {
        $id = $this->targetApplicationId();

        return $id === null || ! $this->user() ? null : FundingApplication::visibleTo($this->user())->find($id);
    }

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
            'id' => ['nullable', new VisibleToUser(FundingApplication::class)],
            'funding_request_id' => ['nullable', new VisibleToUser(FundingRequest::class)],
            // A plain amount, not "5e4" (R3-BUG-022).
            'requested_amount' => ['required', 'numeric', 'min:0', new MoneyAmount],
            'purpose_of_funds' => 'required|string|max:500',
            'purpose_note' => 'nullable|string|max:500',
            // funding_sequence_id, approved_funding, preferred_funding_type.
            ...$this->fundingSequenceRules(),
            // The other funding types the applicant would also consider - never
            // the preferred one again, each once, from the same list.
            'additional_funding_types' => ['nullable', 'array'],
            'additional_funding_types.*' => [
                'string',
                'distinct',
                new KnownFundingType,
                'different:preferred_funding_type',
            ],
            'timeframe_needed' => 'nullable|string|max:255',
            'collateral_available' => 'nullable|string|max:500',
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
            'requested_amount.numeric' => __('borrower_validation.requested_amount_numeric'),
            'requested_amount.min' => __('borrower_validation.requested_amount_min'),
            'purpose_of_funds.string' => __('borrower_validation.purpose_of_funds_string'),
            'purpose_of_funds.max' => __('borrower_validation.purpose_of_funds_max'),
            'approved_funding.string' => __('borrower_validation.approved_funding_string'),
            'approved_funding.max' => __('borrower_validation.approved_funding_max'),
            'purpose_note.string' => __('borrower_validation.purpose_note_string'),
            'purpose_note.max' => __('borrower_validation.purpose_note_max'),
            'preferred_funding_type.string' => __('borrower_validation.preferred_funding_type_string'),
            'preferred_funding_type.max' => __('borrower_validation.preferred_funding_type_max'),
            'additional_funding_types.*.distinct' => __('borrower_validation.additional_funding_type_invalid'),
            'additional_funding_types.*.different' => __('borrower_validation.additional_funding_type_preferred'),
            'timeframe_needed.string' => __('borrower_validation.timeframe_needed_string'),
            'timeframe_needed.max' => __('borrower_validation.timeframe_needed_max'),
            'collateral_available.string' => __('borrower_validation.collateral_available_string'),
            'collateral_available.max' => __('borrower_validation.collateral_available_max'),
            ...$this->fundingSequenceMessages(),
        ];
    }
}

