<?php

namespace App\Services\FCH\Common\PreQualification\AUE;

use App\Models\Borrower\PreQualificationGptResult;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Funding\FundingSequencing;
use App\Models\Funding\LenderVisibility;
use App\Models\Lender\Lender;
use App\Models\Lender\LenderProductCategory;
use App\Models\PreQualification\PreQualificationAgentConfig;
use App\Services\Global\OpenAI\OpenAiAgentsService;
use Illuminate\Support\Carbon;

/**
 * Internal-only underwriting evaluation.
 *
 * Grounds every eligibility judgment in the CURRENT active lender matrix pulled from
 * the database at call time (never hard-coded in the prompt). Output is stored as an
 * "internal" row in pre_qualification_gpt_results and never released to the
 * borrower/partner.
 *
 * The underwriting instructions, the output schema, the model and the reasoning
 * effort are all read from the Pre-Qualification agent on the OpenAI platform at
 * call time. Nothing about how this evaluates is written here — no prompt text,
 * no schema file — so an edit saved on the platform applies to the very next
 * run, with nothing to deploy. Only the facts underwritten against are built
 * here: the borrower, the live lender matrix and the current FCH rule package.
 *
 * With no agent configured the step produces no evaluation and says so. There
 * is deliberately nothing to fall back to: a second set of instructions living
 * in this codebase is exactly the hard-coded underwriting the agent replaces,
 * and it would decide a borrower's result without anyone knowing which of the
 * two had run.
 *
 * Every underwriting judgment here comes from the model response. When the call
 * fails this returns ok:false and stores nothing — it never substitutes a locally
 * computed result, because a hard-coded eligibility rule would be presented as an
 * underwriting outcome without any underwriting having taken place.
 */
class PreQualificationInternalAueService
{
    use ResolvesAgentPromptVersion;

    /**
     * Last-resort label for a stored row, used only when neither the response
     * nor the agent names a version. The instructions themselves live on the
     * agent — this is not a prompt, and nothing is generated from it.
     */
    public const PROMPT_VERSION = 'FCH_Internal_Prequalification_AUE_v3';

    /**
     * The rules that can be evaluated from prequalification data alone. The
     * bank-statement-derived rules (NSF, average balance, deposit counts) are
     * omitted — that data does not exist yet at this stage.
     */
    protected const PREQUAL_EVALUABLE_RULES = [
        'personal_credit',
        'business_credit',
        'revenue',
        'time_in_business',
        'allowed_states',
        'restricted_states',
        'allowed_industries',
        'restricted_industries',
    ];

    /** The lender-side criteria those rules compare against. */
    protected const PREQUAL_EVALUABLE_CRITERIA = [
        'min_fico',
        'min_business_credit_score',
        'min_tib_months',
        'min_monthly_revenue',
        'allowed_states',
        'restricted_states',
        'allowed_industries',
        'restricted_industries',
    ];

    /** Runs the saved Pre-Qualification agent; also holds the OpenAI credential. */
    protected OpenAiAgentsService $agents;

    /** Correlates this run's internal and client rows. */
    protected ?string $runId = null;

    /** OpenAI's identifiers for the call behind the row about to be written. */
    protected ?string $requestId = null;

    public function __construct(OpenAiAgentsService $agents)
    {
        $this->agents = $agents;
    }

    /**
     * @return array{ok: bool, data: ?array, is_fallback: bool, model: ?string, latency_ms: int, error: ?string}
     */
    public function evaluate(PreQualificationRequest $application, ?int $userId = null, ?string $runId = null): array
    {
        $this->runId = $runId;
        $lenderMatrix = $this->buildActiveLenderMatrix();
        $matrixVersion = config('openai.lender_matrix_version', 'runtime') . ':' . now()->format('Y-m-d');

        if (!$this->agents->hasApiKey()) {
            return $this->failed('No OpenAI API key configured.');
        }

        // submission_id and matrix_version are required by the schema and are
        // ours to state, not the model's to infer, so they are supplied as input
        // and checked against the response below.
        $input = [
            'submission_id' => (string) $application->id,
            'matrix_version' => $matrixVersion,
            'borrower' => $this->buildBorrowerPayload($application),
            'active_lender_matrix' => $lenderMatrix,
            'fch_rule_package' => $this->buildRulePackage(),
        ];

        $logContext = [
            'prompt_version' => self::PROMPT_VERSION,
            'pre_qualification_request_id' => $application->id,
            'step' => 'internal_aue',
            'lender_matrix_version' => $matrixVersion,
        ];

        $agent = PreQualificationAgentConfig::find(PreQualificationAgentConfig::TYPE_AGENT);

        if (!$agent->isReady()) {
            return $this->failed('No Pre-Qualification agent is configured in Settings > OpenAI Configuration.');
        }

        // The instructions and the output schema live on the agent, so neither
        // exists in this codebase to be read. Only the facts are built here.
        $result = $this->agents->runStructuredAgent($agent->agent_id, $input, $agent->agentOverrides(), $logContext);

        // Whatever effort the agent is saved with, including none at all — an
        // agent on a model that takes no reasoning effort records none, rather
        // than a value this code picked on its behalf.
        $reasoningEffort = $result['reasoning_effort'] ?? null;

        if (!$result['ok']) {
            return $this->failed($result['error']);
        }

        // Carried to persist() so the stored row points at the exact OpenAI call.
        $this->requestId = $result['request_id'] ?? null;

        $data = $result['data'];

        // A lender the model named but that is not in the active, ISO-eligible
        // matrix is dropped rather than shown to staff as routable.
        $activeLenderNames = collect($lenderMatrix)->pluck('lender_name')->unique()->all();
        $data['active_lender_matches'] = collect((array) ($data['active_lender_matches'] ?? []))
            ->filter(fn ($match) => in_array($match['lender_name'] ?? null, $activeLenderNames, true))
            ->values()
            ->all();

        // The routing recommendation names one of those lenders, so it is scrubbed
        // on the same rule — otherwise dropping the match above would leave the
        // recommendation pointing at a lender no longer offered.
        $primaryLender = $data['routing_recommendation']['primary_lender'] ?? null;
        if ($primaryLender !== null && !in_array($primaryLender, $activeLenderNames, true)) {
            $data['routing_recommendation']['primary_lender'] = null;
        }

        // Ours to state, not the model's: keep the identifiers authoritative even
        // if the response echoed them back changed.
        $data['submission_id'] = (string) $application->id;
        $data['matrix_version'] = $matrixVersion;

        $this->persist(
            $application,
            $data,
            // The version the evaluation itself reports, so a stored row names
            // the prompt that produced it rather than the one this class was
            // written against — which is the whole point of the agent holding
            // the instructions.
            self::promptVersionFor($data, $result),
            $matrixVersion,
            $result['model'],
            $reasoningEffort,
            $result['latency_ms'],
            false,
            $userId
        );

        return [
            'ok' => true,
            'data' => $data,
            'is_fallback' => false,
            'model' => $result['model'],
            'latency_ms' => $result['latency_ms'],
            'error' => null,
        ];
    }

    /**
     * No result. Nothing is persisted, so the application is left without an
     * internal evaluation rather than with a locally invented one.
     */
    protected function failed(?string $reason): array
    {
        return [
            'ok' => false,
            'data' => null,
            'is_fallback' => false,
            'model' => null,
            'latency_ms' => 0,
            'error' => $reason,
        ];
    }

    protected function persist(
        PreQualificationRequest $application,
        array $data,
        string $promptVersion,
        string $matrixVersion,
        ?string $model,
        ?string $reasoningEffort,
        int $latencyMs,
        bool $isPlaceholder,
        ?int $userId
    ): void {
        PreQualificationGptResult::create([
            'pre_qualification_request_id' => $application->id,
            'type' => PreQualificationGptResult::TYPE_INTERNAL,
            'run_id' => $this->runId,
            'prompt_version' => $promptVersion,
            'matrix_version' => $matrixVersion,
            'model' => $model,
            'openai_request_id' => $this->requestId,
            'reasoning_effort' => $reasoningEffort,
            'payload' => $data,
            'is_placeholder' => $isPlaceholder,
            'latency_ms' => $latencyMs,
            'created_by' => $userId,
        ]);
    }

    /**
     * Pull the current active, ISO-eligible lender matrix as runtime data.
     *
     * Every entry carries the four status fields the prompt requires before a
     * lender may be routed to at all (ACTIVE LENDER MATRIX GOVERNANCE: recommend
     * "only to lenders explicitly marked active, approved, usable, and
     * ISO-compatible"). They used to be applied as a query filter and then
     * dropped from the entry, which is why the model — correctly — refused to
     * route and returned an empty match list and a null primary_lender.
     *
     * Entries are keyed by lender + product category + AUS model + criteria
     * rather than by lender_visibilities row. A lender offers the same product
     * on identical criteria across several funding sequences, and one entry per
     * row made those byte-identical in the payload (141 of 282 rows were
     * duplicates). The sequences that share an entry are listed under
     * funding_programs instead, so the collapse loses nothing.
     */
    protected function buildActiveLenderMatrix(): array
    {
        $lenders = Lender::query()
            ->where('status', Lender::STATUS_ACTIVE)
            ->where('iso_status', Lender::STATUS_ACTIVE)
            ->with([
                'visibilities.productCategory',
                'visibilities.fundingSequencing.type',
                'visibilities.fundingSequencing.group',
            ])
            ->get();

        $entries = [];

        foreach ($lenders as $lender) {
            foreach ($lender->visibilities as $visibility) {
                $category = $visibility->productCategory;
                $program = $visibility->fundingSequencing;

                $approved = ($category === null || $category->status === LenderProductCategory::STATUS_ACTIVE)
                    && ($program === null || $program->status === FundingSequencing::STATUS_ACTIVE);

                // A switched-off category or funding program is not routable, so
                // it is dropped rather than offered with approved:false — the
                // same reason inactive lenders never reach the model at all.
                if (!$approved) {
                    continue;
                }

                $criteria = $this->programCriteria($visibility);
                $ausModel = $this->ausModelFor($category, $program);
                $categoryName = $category->name ?? 'General';

                $key = implode('|', [$lender->id, $categoryName, $ausModel, md5(json_encode($criteria))]);

                if (!isset($entries[$key])) {
                    $entries[$key] = [
                        'lender_name' => $lender->name,
                        'product_category' => $categoryName,
                        'aus_model' => $ausModel,
                        'active' => $lender->status === Lender::STATUS_ACTIVE,
                        'approved' => $approved,
                        // Not routable on criteria nobody configured: an entry
                        // with no prequalification-evaluable threshold would
                        // match every borrower vacuously.
                        'usable' => $this->hasPrequalEvaluableCriteria($criteria),
                        'iso_compatible' => $lender->iso_status === Lender::STATUS_ACTIVE,
                        'funding_programs' => [],
                        'program_criteria' => $criteria,
                    ];
                }

                if ($program?->name && !in_array($program->name, $entries[$key]['funding_programs'], true)) {
                    $entries[$key]['funding_programs'][] = $program->name;
                }
            }
        }

        return array_values($entries);
    }

    /**
     * One lender program's underwriting criteria, cast to the types they are
     * compared as. They were previously emitted as the raw column strings
     * ("420", "4200.00"), leaving the model to compare a string threshold
     * against a numeric borrower value.
     */
    protected function programCriteria(LenderVisibility $visibility): array
    {
        $int = static fn ($value) => $value === null || $value === '' ? null : (int) $value;
        $numeric = static fn ($value) => $value === null || $value === '' ? null : (float) $value;
        $list = static fn ($value) => array_values(array_filter((array) $value)) ?: null;

        return array_filter([
            'min_fico' => $int($visibility->min_fico),
            'min_business_credit_score' => $int($visibility->min_business_credit_score),
            'min_tib_months' => $int($visibility->min_tib_months),
            'min_monthly_revenue' => $numeric($visibility->min_monthly_revenue),
            'min_monthly_deposits' => $numeric($visibility->min_monthly_deposits),
            'min_avg_deposits' => $numeric($visibility->min_avg_deposits),
            'min_deposit_count' => $int($visibility->min_deposit_count),
            'min_average_daily_balance' => $numeric($visibility->min_average_daily_balance),
            'max_nsf_count' => $int($visibility->max_nsf_count),
            'max_negative_days' => $int($visibility->max_negative_days),
            'min_dscr' => $numeric($visibility->min_dscr),
            'allowed_states' => $list($visibility->allowed_states),
            'restricted_states' => $list($visibility->restricted_states),
            'allowed_industries' => $list($visibility->allowed_industries),
            'restricted_industries' => $list($visibility->restricted_industries),
        ], fn ($value) => $value !== null);
    }

    /** Whether any criterion here can actually be tested against prequalification data. */
    protected function hasPrequalEvaluableCriteria(array $criteria): bool
    {
        return array_intersect_key($criteria, array_flip(self::PREQUAL_EVALUABLE_CRITERIA)) !== [];
    }

    /**
     * Which of the three AUS models in the prompt governs this program.
     * Config-driven (prequalification_aue.aus_models) so the mapping stays an
     * FCH business rule rather than something this service decides.
     */
    protected function ausModelFor(?LenderProductCategory $category, ?FundingSequencing $program): string
    {
        $map = config('prequalification_aue.aus_models', []);
        $default = $map['default'] ?? 'REGULAR_AUS';
        $unmapped = '_unmapped_';

        return $map['by_product_category'][$category->name ?? $unmapped]
            ?? $map['by_funding_sequence'][$program->name ?? $unmapped]
            ?? $map['by_funding_type'][$program?->type->name ?? $unmapped]
            ?? $map['by_funding_group'][$program?->group->name ?? $unmapped]
            ?? $default;
    }

    /**
     * The current FCH underwriting rule package (config/funding_qualification.php),
     * trimmed to the comparison rules that are actually evaluable from prequalification
     * data (personal/business credit, revenue, time in business, state/industry
     * eligibility). The bank-statement-derived rules (NSF, average balance, deposit
     * counts) are omitted — that data does not exist yet at this stage.
     *
     * The data dictionary travels with the rules because two borrower fields are
     * not what their lender-side counterpart implies: the personal credit answer
     * is a band rather than a score, and the business credit answer is a
     * qualitative bucket with no Paydex equivalent at all.
     */
    protected function buildRulePackage(): array
    {
        $rules = collect(config('funding_qualification.rules', []))
            ->only(self::PREQUAL_EVALUABLE_RULES)
            ->toArray();

        // Prequalification underwrites personal credit on the band the borrower
        // selected, never on the exact bureau score from their profile: sending
        // both gave the model two conflicting credit values. The shared rule
        // addresses borrowers.fico_score, so it is re-pointed here for this
        // scenario only; the stored profile score and the shared config are untouched.
        if (isset($rules['personal_credit'])) {
            $rules['personal_credit']['borrower_field'] = 'borrowers.personal_credit_band';
        }

        return [
            'rules' => $rules,
            // The rules address borrower values as "borrowers.<field>"; those
            // names resolve against the borrower object in this payload.
            'borrower_field_namespace' => 'borrowers.<field> resolves to borrower.<field> in this payload.',
            'data_dictionary' => [
                'personal_credit_band' => 'Borrower-selected band from the prequalification wizard, e.g. "Good (670-739)". It is the only personal credit value in this payload; no exact score is supplied. Evaluate the personal_credit rule by comparing personal_credit_band_min and personal_credit_band_max against min_fico. Never reduce the band to a single point value, and never flag the absence of an exact score.',
                'paydex_score' => 'Bureau business credit score from the borrower credit profile. Null means none exists.',
                'business_credit_self_assessment' => 'Qualitative borrower self-assessment (No Business Credit | Low | Medium | High). It has no numeric equivalent and must not be compared to min_business_credit_score. Treat min_business_credit_score as not evaluable while paydex_score is null.',
                'business_start_date' => 'Resolved start date for the time_in_business date_compare rule. Compare time_in_business_months against min_tib_months.',
                'business_state' => 'The state the business operates in — the value the allowed_states and restricted_states rules test. state_of_incorporation is a separate fact and is not a substitute for it.',
                'aus_model' => 'The AUS model governing each lender matrix entry, set by FCH from the product category. Prequalification does not collect property occupancy, so a REAL_ESTATE_INVESTOR_MODEL entry is not yet confirmed non-owner-occupied: treat occupancy as a required condition on any real-estate path rather than assuming it.',
            ],
        ];
    }

    /**
     * The borrower profile sent to the model.
     *
     * A field the borrower did not supply is sent as null, never as a substitute
     * value. A placeholder like "Working Capital" or a zero revenue is
     * indistinguishable from a real answer, so the model would underwrite against
     * a figure nobody provided. null says "not provided", and the model raises it
     * as a risk flag instead of quietly assuming it.
     *
     * Field names match the borrower_field identifiers in the rule package
     * (config/funding_qualification.php) so the supplied rules resolve against
     * this object. They previously did not: the rules address
     * borrowers.fico_score, business_state, business_industry_type and
     * business_start_date, none of which this payload contained.
     */
    protected function buildBorrowerPayload(PreQualificationRequest $application): array
    {
        $fullName = trim(($application->first_name ?? '') . ' ' . ($application->last_name ?? ''));
        $numeric = static fn ($value) => $value === null || $value === '' ? null : (float) $value;

        // Loaded explicitly rather than relied on from the caller: callers eager-load
        // borrower inconsistently, and the credit profile is not eager-loaded
        // anywhere. Lazy loading is blocked outside production for models read as a
        // collection, so an evaluate() call made over one would fail here otherwise.
        $application->loadMissing('borrower.creditProfile');

        $borrower = $application->borrower;
        $creditProfile = $borrower?->creditProfile;

        $band = $this->personalCreditBand($application->personal_credit_score);
        $startDate = $application->resolvedBusinessStartDate();
        $startDate = $startDate ? Carbon::parse($startDate) : null;

        return [
            'full_name' => $fullName ?: null,
            'email' => $application->email ?: ($borrower?->email ?: null),
            'business_name' => $application->business_name ?: null,
            'entity_type' => $application->business_structure ?: null,
            'business_industry_type' => $application->industry_type ?: null,

            // The operating state is what the state-eligibility rules test.
            // state_of_incorporation was being sent in its place, and the two
            // differ often enough to route a borrower to a lender that does not
            // lend where the business actually operates.
            'business_state' => $borrower?->business_state ?: null,
            'state_of_incorporation' => $application->state_of_incorporation ?: null,

            // min_tib_months is a month count, so a month count is what goes
            // across. The display string ("2 Years 3 Months") comes from a view
            // helper and was previously the only time-in-business value in the
            // payload, leaving the model to parse prose into a threshold.
            'business_start_date' => $startDate?->toDateString(),
            'time_in_business_months' => $startDate ? (int) $startDate->diffInMonths(now()) : null,
            'time_in_business_display' => $application->resolvedYearsInBusiness() ?: null,

            'monthly_revenue' => $numeric($application->monthly_revenue),
            'annual_revenue' => $numeric($application->annual_revenue),

            // Personal credit is the band the borrower selected in the wizard, and
            // only that. The profile's exact fico_score and all score provenance
            // fields are deliberately not sent: alongside the band they gave the
            // model two conflicting credit values. The band's bounds are what
            // min_fico is compared against.
            'personal_credit_band' => $application->personal_credit_score ?: null,
            'personal_credit_band_min' => $band['min'],
            'personal_credit_band_max' => $band['max'],

            'paydex_score' => $creditProfile?->paydex_score !== null ? (int) $creditProfile->paydex_score : null,
            'business_credit_self_assessment' => $application->estimated_business_credit_score ?: null,

            'funding_purpose' => $application->purpose_of_funding ?: null,
            'funding_urgency' => $application->funding_urgency ?: null,
            'amount_requested' => $numeric($application->funding_requested_amount),
            // Left null rather than defaulted to false: "no debt disclosed" and
            // "the question was never answered" are different underwriting facts.
            'has_current_debt' => $application->existing_business_debt === null
                ? null
                : (bool) $application->existing_business_debt,
            'debt_balance' => $numeric($application->debt_balance),
            'notes' => $application->notes ?: ($application->purpose_note ?: null),
            'validator_warnings' => collect((array) $application->validation_issues)
                ->where('severity', '!=', 'INFO')
                ->pluck('internal_message')
                ->reject(fn ($message) => self::isExactCreditScoreWarning($message))
                ->values()
                ->all(),
        ];
    }

    /**
     * Whether a validator warning concerns the exact FICO score or credit score
     * provenance. Those are dropped from the prequalification payload so they
     * cannot reintroduce the exact-score conflict the band replaces.
     */
    public static function isExactCreditScoreWarning(mixed $message): bool
    {
        return (bool) preg_match(
            '/fico|bureau|exact (personal )?(credit )?score|credit[ _]score[ _]source|verified credit|(?<!business )credit score.{0,60}(source|provenance|verified)/i',
            (string) $message
        );
    }

    /**
     * The numeric bounds of a borrower-selected credit band. An unrecognised or
     * absent band yields nulls rather than a guessed range.
     */
    protected function personalCreditBand(?string $band): array
    {
        $bounds = config('prequalification_aue.personal_credit_bands', [])[$band] ?? null;

        return [
            'min' => $bounds['min'] ?? null,
            'max' => $bounds['max'] ?? null,
        ];
    }
}
