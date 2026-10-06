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
            // What the validator agent wrote FOR the borrower about each thing
            // they have to correct. Only the borrower-addressed message of each
            // blocking finding — see borrowerCorrections().
            'validation_corrections' => $this->borrowerCorrections(),
            'gpt_status' => $this->gpt_status ?? null,
            // Converted to the viewer's timezone, like every other timestamp in
            // this response. It was formatted straight off the stored UTC value,
            // so "Evaluated" read hours away from the Submitted date beside it
            // and from the clock on the borrower's own machine.
            'gpt_generated_at' => toTimezone($this->gpt_generated_at, null, 'Y-m-d h:i A'),
            'underwriting_result' => $this->clientAueResult(),
        ];
    }

    /**
     * The blocking findings, as the validator agent worded them for the borrower.
     *
     * A finding carries two texts: user_message, written to be read by the
     * borrower, and internal_message, written for FCH staff — plus
     * suggested_action, which is staff guidance. Only user_message crosses to
     * this side, by name rather than by exclusion, so a new staff-only field in
     * the agent's schema cannot reach a borrower by being forgotten here. That
     * is the same boundary PreQualificationRequestResource draws when it keeps
     * validation_issues host-only.
     *
     * Non-blocking findings are left out: a warning is not something the
     * borrower is being asked to go and fix.
     *
     * @return array<int, array{field: ?string, message: string}>
     */
    protected function borrowerCorrections(): array
    {
        return collect((array) ($this->validation_issues ?? []))
            ->filter(fn ($issue) => is_array($issue) && ($issue['severity'] ?? null) === 'BLOCKING')
            ->map(fn ($issue) => [
                // Which answer to go back to, when the agent named one.
                'field' => is_string($issue['field'] ?? null) && $issue['field'] !== '' ? $issue['field'] : null,
                'message' => trim((string) ($issue['user_message'] ?? '')),
            ])
            // A finding the agent gave no borrower wording for has nothing to
            // show; the internal text is not substituted in its place.
            ->filter(fn ($issue) => $issue['message'] !== '')
            ->values()
            ->all();
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

        // A run the agent blocked also ends COMPLETED — the evaluation was
        // produced, it is only withheld — and writes no borrower row, so the
        // latest one on file belongs to an earlier run.
        if (($this->validation_status ?? null) === PreQualificationRequest::VALIDATION_INCORRECT) {
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