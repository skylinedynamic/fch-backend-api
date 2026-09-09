<?php

namespace App\Services\FCH\Common\PreQualification\Validator;

use App\Models\Borrower\PreQualificationRequest;
use App\Services\Global\OpenAI\OpenAiResponsesService;

/**
 * FCH Pre-Qualification Form Data Integrity Validator v1.0
 *
 * Runs after a submission is saved and before any AUE generation. Never makes a
 * credit decision — only checks the submitted data for completeness, internal
 * consistency, and plausibility, per FCH_Prequalification_Data_Integrity_Validator_v1_Instructions.txt.
 */
class PreQualificationDataIntegrityValidatorService
{
    public const PROMPT_VERSION = 'FCH_Prequalification_Data_Integrity_Validator_v1';

    protected OpenAiResponsesService $client;

    public function __construct(OpenAiResponsesService $client)
    {
        $this->client = $client;
    }

    /**
     * @return array{blocking: bool, status: string, borrower_message: ?string}
     */
    public function validate(PreQualificationRequest $application): array
    {
        $payload = $this->buildPayload($application);

        if (!$this->client->hasApiKey()) {
            return $this->persistSystemError($application, 'No OpenAI API key configured.');
        }

        $instructions = file_get_contents(__DIR__ . '/FCH_Prequalification_Data_Integrity_Validator_v1_Instructions.txt');
        $schema = json_decode(file_get_contents(__DIR__ . '/FCH_Prequalification_Data_Integrity_Validator_v1_Response_Schema.json'), true);

        $result = $this->client->run(
            model: $this->client->defaultModel(),
            instructions: $instructions,
            input: $payload,
            schemaName: 'fch_prequalification_data_integrity_validator_v1',
            schema: $schema,
            reasoningEffort: 'low',
            logContext: [
                'prompt_version' => self::PROMPT_VERSION,
                'pre_qualification_request_id' => $application->id,
                'step' => 'data_integrity_validator',
            ],
        );

        if (!$result['ok']) {
            return $this->persistSystemError($application, $result['error']);
        }

        $data = $result['data'];
        $issues = array_values(array_filter((array) ($data['issues'] ?? [])));

        // Derive the status from the issues themselves rather than trusting the model's
        // self-declared validation_status — it has been observed returning
        // NEEDS_CORRECTION while reporting only WARNING-severity issues, which would
        // otherwise persist a status that contradicts its own findings.
        $blocking = collect($issues)->contains(fn ($issue) => ($issue['severity'] ?? null) === 'BLOCKING');
        $status = $blocking
            ? PreQualificationRequest::VALIDATION_INCORRECT
            : PreQualificationRequest::VALIDATION_PASSED;

        $application->validation_status = $status;
        $application->validation_version = '1.0';
        $application->validation_summary = (string) ($data['summary'] ?? '');
        $application->validation_issues = $issues;
        $application->validated_at = now();
        $application->save();

        return [
            'blocking' => $blocking,
            'status' => $status,
            'borrower_message' => $blocking ? ($data['borrower_message'] ?? null) : null,
        ];
    }

    protected function persistSystemError(PreQualificationRequest $application, ?string $reason): array
    {
        $application->validation_status = PreQualificationRequest::VALIDATION_SYSTEM_ERROR;
        $application->validation_version = '1.0';
        $application->validation_summary = 'Automated validation is temporarily unavailable. Your information has been saved and will not be lost.';
        $application->validation_issues = [];
        $application->validated_at = now();
        $application->save();

        \Illuminate\Support\Facades\Log::warning('FCH Pre-Qualification data integrity validator system error', [
            'pre_qualification_request_id' => $application->id,
            'reason' => $reason,
        ]);

        // A system/infrastructure error is not a data-quality finding about the
        // borrower's submission, so it does not block downstream AUE generation.
        return [
            'blocking' => false,
            'status' => PreQualificationRequest::VALIDATION_SYSTEM_ERROR,
            'borrower_message' => null,
        ];
    }

    protected function buildPayload(PreQualificationRequest $application): array
    {
        return [
            // The model has no reliable notion of "today", so every date comparison
            // must be made against this value rather than an assumed current date.
            'as_of_date' => now()->toDateString(),
            'first_name' => $application->first_name,
            'last_name' => $application->last_name,
            'business_name' => $application->business_name,
            'industry_type' => $application->industry_type,
            'business_structure' => $application->business_structure,
            'state_of_incorporation' => $application->state_of_incorporation,
            'business_start_date' => $application->resolvedBusinessStartDate(),
            'years_in_business' => $application->resolvedYearsInBusiness(),
            'monthly_revenue' => $application->monthly_revenue,
            'annual_revenue' => $application->annual_revenue,
            'personal_credit_score' => $application->personal_credit_score,
            'estimated_business_credit_score' => $application->estimated_business_credit_score,
            'credit_score_source' => $application->credit_score_source,
            'funding_requested_amount' => $application->funding_requested_amount,
            'funding_urgency' => $application->funding_urgency,
            'purpose_of_funding' => $application->purpose_of_funding,
            'purpose_note' => $application->purpose_note,
            'existing_business_debt' => (bool) $application->existing_business_debt,
            'debt_balance' => $application->debt_balance,
            'notes' => $application->notes,
        ];
    }
}
