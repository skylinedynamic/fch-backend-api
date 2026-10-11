<?php

namespace App\Console\Commands;

use App\Models\Borrower\PreQualificationGptResult;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Lender\Lender;
use App\Services\FCH\Common\Application\PreQualificationAiService;
use App\Services\FCH\Common\PreQualification\AUE\PreQualificationInternalAueService;
use Illuminate\Console\Command;

/**
 * Pre-deployment check for the Internal AUE.
 *
 * Inspects the payload the AUE would send, then optionally runs the real
 * evaluation and reports whether a lender was actually identified. The payload
 * checks are the ones that silently failed before: the matrix reached the model
 * with no status fields and with byte-identical duplicate entries, and the
 * borrower object used field names the supplied rule package does not address.
 *
 *   php artisan fch:verify-internal-aue                 # payload checks only
 *   php artisan fch:verify-internal-aue --audit         # report on the lender records themselves
 *   php artisan fch:verify-internal-aue 93 --live       # also run the real evaluation
 *   php artisan fch:verify-internal-aue 93 --pipeline   # the whole borrower-submit path
 */
class VerifyInternalAue extends Command
{
    protected $signature = 'fch:verify-internal-aue
        {application? : Pre-qualification request id (defaults to the most recent submitted one)}
        {--audit : Audit every lender record for the status fields, criteria and AUS mapping the AUE needs}
        {--live : Run the real OpenAI evaluation (costs a call) and report the routing result}
        {--pipeline : Run the whole borrower-submit pipeline (validator, internal AUE, client AUE) and check what each audience sees}';

    protected $description = 'Verify the Internal AUE payload and, with --live, that it identifies an eligible lender';

    /** The status fields the prompt requires on every matrix entry before it will route. */
    private const REQUIRED_STATUS_FIELDS = ['active', 'approved', 'usable', 'iso_compatible'];

    /** Borrower fields the trimmed rule package addresses as borrowers.<field>. */
    private const REQUIRED_BORROWER_FIELDS = [
        'personal_credit_band',
        'personal_credit_band_min',
        'personal_credit_band_max',
        'paydex_score',
        'monthly_revenue',
        'business_start_date',
        'business_state',
        'business_industry_type',
    ];

    public function handle(PreQualificationInternalAueService $aue): int
    {
        if ($this->option('audit')) {
            return $this->auditLenderRecords() ? self::SUCCESS : self::FAILURE;
        }

        $application = $this->resolveApplication();

        if ($application === null) {
            $this->components->error('No pre-qualification request found to verify against.');

            return self::FAILURE;
        }

        $this->components->info("Application #{$application->id} — {$application->first_name} {$application->last_name}");

        $matrix = $this->invoke($aue, 'buildActiveLenderMatrix');
        $borrower = $this->invoke($aue, 'buildBorrowerPayload', $application);

        $passed = $this->reportMatrix($matrix) & $this->reportBorrower($borrower);

        if ($this->option('pipeline')) {
            return $this->reportPipelineRun($application) && $passed ? self::SUCCESS : self::FAILURE;
        }

        if (!$this->option('live')) {
            $this->newLine();
            $this->components->warn('Payload checks only. Re-run with --live to confirm the AUE identifies a lender, or --pipeline for the whole submit path.');

            return $passed ? self::SUCCESS : self::FAILURE;
        }

        return $this->reportLiveRun($aue, $application) && $passed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Read-only report on whether the lender records in this environment carry
     * what the AUE needs. Run it against Production to answer "do the Production
     * records contain all required status fields, program criteria and AUS
     * rules" with the records themselves rather than an assumption.
     */
    private function auditLenderRecords(): bool
    {
        $lenders = Lender::with(['visibilities.productCategory', 'visibilities.fundingSequencing'])->get();

        $inactive = [];
        $nonIso = [];
        $noPrograms = [];
        $noCriteria = [];
        $unrestrictedStates = [];
        $unmappedAusCategories = [];
        $duplicatePrograms = [];

        $categoryMap = config('prequalification_aue.aus_models.by_product_category', []);

        foreach ($lenders as $lender) {
            $label = $lender->name;

            if ($lender->status !== Lender::STATUS_ACTIVE) {
                $inactive[] = $label;
                continue;
            }

            if ($lender->iso_status !== Lender::STATUS_ACTIVE) {
                $nonIso[] = $label;
                continue;
            }

            if ($lender->visibilities->isEmpty()) {
                $noPrograms[] = $label;
                continue;
            }

            $seen = [];

            foreach ($lender->visibilities as $visibility) {
                $category = $visibility->productCategory->name ?? 'General';
                $program = "{$label} — {$category}";

                $criteria = array_filter([
                    'min_fico' => $visibility->min_fico,
                    'min_business_credit_score' => $visibility->min_business_credit_score,
                    'min_tib_months' => $visibility->min_tib_months,
                    'min_monthly_revenue' => $visibility->min_monthly_revenue,
                ], fn ($value) => $value !== null);

                if ($criteria === []) {
                    $noCriteria[] = $program;
                }

                if (empty($visibility->allowed_states) && empty($visibility->restricted_states)) {
                    $unrestrictedStates[] = $program;
                }

                if (!isset($categoryMap[$category])) {
                    $unmappedAusCategories[$category] = $category;
                }

                $signature = $category . '|' . md5(json_encode($criteria));

                if (isset($seen[$signature])) {
                    $duplicatePrograms[] = $program;
                }

                $seen[$signature] = true;
            }
        }

        $this->newLine();
        $this->components->info('Lender record audit — ' . $lenders->count() . ' lenders');

        $this->reportGroup('inactive (excluded from the matrix)', $inactive);
        $this->reportGroup('active but not ISO-compatible (excluded)', $nonIso);
        $this->reportGroup('active with no programs configured', $noPrograms);
        $this->reportGroup('programs with no prequalification-evaluable criteria', $noCriteria);
        $this->reportGroup('programs with no state list — treated as unrestricted', $unrestrictedStates);
        $this->reportGroup('product categories with no explicit AUS mapping (fall back to the default)', array_values($unmappedAusCategories));
        $this->reportGroup('programs that collapse to a duplicate matrix entry', $duplicatePrograms);

        $routable = $lenders
            ->where('status', Lender::STATUS_ACTIVE)
            ->where('iso_status', Lender::STATUS_ACTIVE)
            ->filter(fn ($lender) => $lender->visibilities->isNotEmpty());

        $this->newLine();

        return $this->check(
            'at least one lender is routable',
            $routable->isNotEmpty(),
            'no active, ISO-compatible lender has any program configured',
        );
    }

    private function reportGroup(string $label, array $items): void
    {
        $this->newLine();

        if ($items === []) {
            $this->line("  <fg=green>none</> — {$label}");

            return;
        }

        $this->line('  <fg=yellow>' . count($items) . "</> — {$label}");

        foreach (array_slice($items, 0, 12) as $item) {
            $this->line("      · {$item}");
        }

        if (count($items) > 12) {
            $this->line('      · … ' . (count($items) - 12) . ' more');
        }
    }

    private function resolveApplication(): ?PreQualificationRequest
    {
        $id = $this->argument('application');

        if ($id !== null) {
            return PreQualificationRequest::find((int) $id);
        }

        return PreQualificationRequest::whereNotNull('personal_credit_score')
            ->latest('id')
            ->first();
    }

    private function reportMatrix(array $matrix): bool
    {
        $this->newLine();
        $this->components->info('Active lender matrix');

        $signatures = array_map(fn ($entry) => md5(json_encode($entry)), $matrix);
        $duplicates = count($signatures) - count(array_unique($signatures));

        $missingStatus = array_filter(
            $matrix,
            fn ($entry) => array_diff(self::REQUIRED_STATUS_FIELDS, array_keys($entry)) !== [],
        );

        $withoutCriteria = array_filter($matrix, fn ($entry) => empty($entry['program_criteria']));
        $unusable = array_filter($matrix, fn ($entry) => ($entry['usable'] ?? false) !== true);

        $this->line("  entries .................. " . count($matrix));
        $this->line("  payload size ............. " . number_format(strlen(json_encode($matrix))) . ' bytes');
        $this->line("  AUS models ............... " . json_encode(array_count_values(array_column($matrix, 'aus_model'))));

        $ok = $this->check('every entry carries active/approved/usable/iso_compatible', count($missingStatus) === 0, count($missingStatus) . ' missing');
        $ok &= $this->check('no duplicate entries', $duplicates === 0, $duplicates . ' duplicates');
        $ok &= $this->check('every entry carries program criteria', count($withoutCriteria) === 0, count($withoutCriteria) . ' empty');
        $ok &= $this->check('matrix is not empty', $matrix !== [], 'no active lenders');

        if ($unusable !== []) {
            $this->components->warn(count($unusable) . ' entries are marked usable:false — no prequalification-evaluable criteria configured.');
        }

        return (bool) $ok;
    }

    private function reportBorrower(array $borrower): bool
    {
        $this->newLine();
        $this->components->info('Borrower payload');

        $missing = array_diff(self::REQUIRED_BORROWER_FIELDS, array_keys($borrower));

        $ok = $this->check(
            'rule-package field names resolve',
            $missing === [],
            'missing: ' . implode(', ', $missing),
        );

        $ok &= $this->check(
            'time in business is a month count',
            array_key_exists('time_in_business_months', $borrower)
                && ($borrower['time_in_business_months'] === null || is_int($borrower['time_in_business_months'])),
            'not an integer month count',
        );

        // Prequalification credit is the borrower-selected band only; the exact
        // profile FICO and score provenance fields must never reach the AUE.
        $forbidden = array_values(array_intersect(
            ['fico_score', 'fico_score_source', 'personal_credit_band_source'],
            array_keys($borrower),
        ));
        $ok &= $this->check(
            'no exact FICO or score provenance fields',
            $forbidden === [],
            'present: ' . implode(', ', $forbidden),
        );

        $exactScoreWarnings = array_filter(
            (array) ($borrower['validator_warnings'] ?? []),
            fn ($message) => PreQualificationInternalAueService::isExactCreditScoreWarning($message),
        );
        $ok &= $this->check(
            'no exact FICO validator warnings',
            $exactScoreWarnings === [],
            count($exactScoreWarnings) . ' exact-score warning(s) still in payload',
        );

        return (bool) $ok;
    }

    private function reportLiveRun(PreQualificationInternalAueService $aue, PreQualificationRequest $application): bool
    {
        $this->newLine();
        $this->components->info('Live evaluation');

        $result = ['ok' => false, 'data' => null, 'model' => null, 'latency_ms' => 0, 'error' => 'Evaluation did not run.'];

        $this->components->task('Calling the Internal AUE', function () use ($aue, $application, &$result) {
            $result = $aue->evaluate($application, null, 'verify-' . now()->timestamp);

            return $result['ok'];
        });

        if (!$result['ok']) {
            $this->components->error('Evaluation failed: ' . $result['error']);

            return false;
        }

        $data = $result['data'];
        $routing = $data['routing_recommendation'] ?? [];
        $matches = $data['active_lender_matches'] ?? [];
        $primary = $routing['primary_lender'] ?? null;

        $this->line("  model .................... " . $result['model']);
        $this->line("  latency .................. " . number_format($result['latency_ms']) . ' ms');
        $this->line("  AUS model ................ " . ($routing['aus_model'] ?? '—'));
        $this->line("  stance ................... " . ($routing['stance'] ?? '—'));
        $this->line("  next action .............. " . ($routing['next_action'] ?? '—'));
        $this->line("  primary lender ........... " . ($primary ?? '—'));
        $this->line("  active lender matches .... " . count($matches));

        foreach (array_slice($matches, 0, 10) as $match) {
            $this->line(sprintf(
                '    · %s [%s] — %s',
                $match['lender_name'] ?? '?',
                $match['lender_status'] ?? '?',
                $match['fit_status'] ?? '?',
            ));
        }

        $ok = $this->check('an eligible lender was identified', $matches !== [], 'active_lender_matches is empty');
        $ok &= $this->check('a primary lender was routed', $primary !== null, 'primary_lender is null');

        if ($primary === null) {
            $this->components->warn('Reason given: ' . ($routing['reason'] ?? 'none supplied'));
        }

        return (bool) $ok;
    }

    /**
     * Run the exact pipeline a borrower submit triggers, then check what each
     * audience ends up with: staff get a routable lender, the borrower gets an
     * assessment with no lender named anywhere in it.
     */
    private function reportPipelineRun(PreQualificationRequest $application): bool
    {
        $this->newLine();
        $this->components->info('Full submit pipeline (one agent → internal evaluation → borrower result)');

        $result = null;
        $error = null;

        $this->components->task('Running PreQualificationAiService::evaluate()', function () use ($application, &$result, &$error) {
            try {
                $result = app(PreQualificationAiService::class)->evaluate($application, null);

                return true;
            } catch (\Throwable $exception) {
                $error = $exception;

                return false;
            }
        });

        if ($error !== null) {
            $this->components->error(get_class($error) . ': ' . $error->getMessage());
            $this->line('  at ' . $error->getFile() . ':' . $error->getLine());

            return false;
        }

        $ok = $this->check('pipeline completed without throwing', true, '');

        $internal = $result['internal'] ?? null;
        $client = $result['client'] ?? null;

        // The agent calling the submitted answers CONFLICTING or INSUFFICIENT is
        // a correct outcome, not a pipeline failure: the borrower is asked to fix
        // them and no assessment is built on them. Reported as what it is.
        if ($result['blocked'] ?? false) {
            $this->components->warn('The agent flagged the submitted data — the borrower is asked to correct it.');
            $this->line('  data_status .............. ' . ($internal['data_status'] ?? '—'));
            $this->line('  borrower is told ......... ' . ($result['borrower_message'] ?? '—'));
            $this->line('  internal memo kept ....... ' . (is_array($internal) ? 'yes' : 'no'));
            $this->line('  borrower assessment ...... withheld, as it should be');

            return (bool) $this->check('the evaluation behind the flag was kept for staff', is_array($internal), 'internal is null');
        }

        $ok &= $this->check('evaluation did not fall back', !($result['is_fallback'] ?? false), 'a fallback result was returned');
        $ok &= $this->check('internal evaluation produced', is_array($internal), 'internal is null');
        $ok &= $this->check('borrower assessment produced', is_array($client), 'client is null');

        if (!is_array($internal) || !is_array($client)) {
            return false;
        }

        $this->newLine();
        $this->components->info('What FCH staff see (admin)');

        $matches = $internal['active_lender_matches'] ?? [];
        $primary = $internal['routing_recommendation']['primary_lender'] ?? null;
        $names = array_values(array_filter(array_column($matches, 'lender_name')));
        $realNames = Lender::where('status', Lender::STATUS_ACTIVE)
            ->where('iso_status', Lender::STATUS_ACTIVE)
            ->pluck('name')
            ->all();

        $this->line('  primary lender ........... ' . ($primary ?? '—'));
        $this->line('  matches .................. ' . implode(', ', $names ?: ['—']));

        // Identifying eligible lenders is the requirement. Naming one primary
        // lender is a routing decision the AUE is allowed to withhold — the
        // prompt tells it to hold for manual review when data conflicts or
        // required inputs are missing, and a held file is a correct outcome.
        // The defect this command exists to catch is the opposite: no lender
        // identified at all because the payload never said any were routable.
        $ok &= $this->check('eligible lenders were identified', $names !== [], 'active_lender_matches is empty');
        $ok &= $this->check('every named lender exists and is active', array_diff($names, $realNames) === [], 'not in the matrix: ' . implode(', ', array_diff($names, $realNames)));
        $ok &= $this->check('the primary lender exists and is active', $primary === null || in_array($primary, $realNames, true), "\"{$primary}\" is not in the matrix");
        $ok &= $this->check('no lender is listed twice', count($names) === count(array_unique($names)), 'duplicate lender names in the matches');

        $stance = $internal['routing_recommendation']['stance'] ?? '';
        $held = in_array($stance, ['MANUAL_REVIEW', 'INSUFFICIENT_DATA', 'CONDITIONAL_REVIEW', 'FOUNDATION_REQUIRED'], true);

        if ($primary === null) {
            $reason = $internal['routing_recommendation']['reason'] ?? 'none supplied';

            // A hold has to be justified by the file, not by the payload. If the
            // reason still blames missing matrix status, the payload regressed.
            $blamesPayload = (bool) preg_match('/matrix|status field|active lender status|no active lender/i', $reason);

            $ok &= $this->check(
                'primary lender withheld for an underwriting reason, not a payload gap',
                $held && !$blamesPayload,
                "stance {$stance}: {$reason}",
            );

            $this->components->warn("No primary lender routed. Stance {$stance} — {$reason}");
        }

        $this->newLine();
        $this->components->info('What the borrower sees (client assessment)');

        // The borrower half is swept whole rather than field by field: a lender
        // name can surface in any free-text string the client model composed,
        // not only in a field that was meant to carry one.
        $clientText = json_encode($client);
        $leaked = array_values(array_filter(
            $realNames,
            fn ($name) => stripos($clientText, $name) !== false,
        ));

        $this->line('  headline ................. ' . ($client['headline'] ?? $client['summary'] ?? '—'));
        $this->line('  payload size ............. ' . number_format(strlen($clientText)) . ' bytes');

        $ok &= $this->check('no lender name appears anywhere in the borrower payload', $leaked === [], 'leaked: ' . implode(', ', $leaked));
        $ok &= $this->check('borrower payload carries no routing recommendation', !isset($client['routing_recommendation']), 'routing_recommendation is present');
        $ok &= $this->check('borrower payload carries no lender matches', !isset($client['active_lender_matches']), 'active_lender_matches is present');

        $this->newLine();
        $this->components->info('Stored rows');

        foreach (['internal', 'client'] as $type) {
            $row = PreQualificationGptResult::where('pre_qualification_request_id', $application->id)
                ->where('type', $type)
                ->latest('id')
                ->first();

            $this->line(sprintf(
                '  %-9s row #%s | prompt %s | placeholder: %s',
                $type,
                $row?->id ?? '—',
                $row?->prompt_version ?? '—',
                $row?->is_placeholder ? 'yes' : 'no',
            ));

            $ok &= $this->check("{$type} result was persisted", $row !== null, 'no row written');
            $ok &= $this->check("{$type} result is not a placeholder", !($row?->is_placeholder), 'stored as a placeholder');
        }

        return (bool) $ok;
    }

    private function check(string $label, bool $passed, string $failureDetail): bool
    {
        $this->line(sprintf(
            '  <fg=%s>%s</> %s%s',
            $passed ? 'green' : 'red',
            $passed ? 'PASS' : 'FAIL',
            $label,
            $passed ? '' : " ({$failureDetail})",
        ));

        return $passed;
    }

    /** The payload builders are the thing under test, so they are read directly. */
    private function invoke(PreQualificationInternalAueService $aue, string $method, mixed ...$arguments): array
    {
        return (new \ReflectionMethod($aue, $method))->invoke($aue, ...$arguments);
    }
}
