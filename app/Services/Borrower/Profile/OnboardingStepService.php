<?php

namespace App\Services\Borrower\Profile;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\CreditProfileRequest;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use App\Models\Partner\Partner;
use App\Models\Subscription\Subscription;
use App\Models\Walkthrough\WalkthroughProgress;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Where a borrower is in the Getting Started journey. There are two, chosen by
 * account type and reported as `flow`:
 *
 * Paid clients (`paid`), the walkthrough proper:
 *
 *   complete_profile -> credit_profile -> verify_all -> upload_documents -> results_ready -> completed
 *
 * 0. complete_profile: fill in every required field of Personal Information,
 *    Business Details and Financial (the Complete Your Profile popup's list).
 * 1. credit_profile: submit the credit profile form. The portal then lands
 *    the borrower on the Step-By-Step Guide.
 * 2. verify_all: submit every Tier 0 task from the Step-By-Step Guide (Verify
 *    All); a rejected task brings the borrower back here to resubmit it.
 * 3. upload_documents: upload a document in every required category on the
 *    Documents page (Others is optional, and a rejected document doesn't
 *    count). The borrower doesn't wait for Tier 0 to be verified first.
 * 4. results_ready: with the credit profile submitted, every Tier 0 task
 *    submitted and every document uploaded (`results_ready` in the response),
 *    the portal shows the results and the choice of what to look at next (see
 *    choosePath() and PATHS). FCH's verification of Tier 0 and the credit
 *    profile is not waited for. Showing them finishes the journey (see
 *    complete()); the choice can still be made afterwards.
 *
 * No step can be skipped: every paid borrower has to get through Tier 0. The
 * skipping machinery (skip(), resume(), `pending_steps`) is kept but only
 * applies to SKIPPABLE_STEPS, which is empty, so skips saved back when Verify
 * All and the old review step could be skipped no longer count either. A skip only
 * postpones a step, it doesn't do it: the borrower moves on, but the step is
 * reported in `pending_steps` until it really is done, and going back to it
 * (see resume()) makes it the current step again.
 *
 * Free-trial borrowers (`free_trial`) only get the start of it:
 *
 *   complete_profile -> credit_profile -> completed
 *
 * No documents, no Tier 0, no results to choose from, nothing to skip, and no
 * payment needed.
 *
 * A paid journey starts once the borrower has paid; paying has its own prompt
 * in the portal (the pending-invoice banner), so until then there is no
 * current step.
 * After that, the current step is the first one not yet done. The borrower
 * portal turns it into a single "what to do next" suggestion.
 *
 * Progress lives in `walkthrough_progress`, not on the borrowers table: the
 * borrower's `borrower_onboarding` row for the paid journey and
 * `borrower_free_trial` for the other, so moving to a paid plan starts the
 * paid journey afresh. Partners will have walkthroughs of their own in the
 * same table.
 */
class OnboardingStepService
{
    public const STEP_COMPLETE_PROFILE = 'complete_profile';
    public const STEP_CREDIT_PROFILE = 'credit_profile';
    public const STEP_VERIFY_ALL = 'verify_all';
    public const STEP_RESULTS_READY = 'results_ready';
    public const STEP_UPLOAD_DOCUMENTS = 'upload_documents';
    public const STEP_COMPLETED = 'completed';

    public const FLOW_PAID = 'paid';
    public const FLOW_FREE_TRIAL = 'free_trial';

    /** The paid journey. */
    public const STEPS = [
        self::STEP_COMPLETE_PROFILE,
        self::STEP_CREDIT_PROFILE,
        self::STEP_VERIFY_ALL,
        self::STEP_UPLOAD_DOCUMENTS,
        self::STEP_RESULTS_READY,
    ];

    /** The free-trial journey: just the start of the paid one. */
    public const FREE_TRIAL_STEPS = [
        self::STEP_COMPLETE_PROFILE,
        self::STEP_CREDIT_PROFILE,
    ];

    /*
     * What a borrower with their results ready can choose to look at, each
     * with a page of its own in the portal: Credit Card Stacking, Credit
     * Builder > Credit Accounts, Funding, Appointment.
     */
    public const PATH_CARD_STACKING = 'card_stacking';
    public const PATH_SINGLE_CARD = 'single_card';
    public const PATH_FUNDING = 'funding';
    public const PATH_ADVISOR = 'advisor';

    public const PATHS = [
        self::PATH_CARD_STACKING,
        self::PATH_SINGLE_CARD,
        self::PATH_FUNDING,
        self::PATH_ADVISOR,
    ];

    /**
     * Steps a borrower may skip (paid journey only). None: every paid borrower
     * has to get through Tier 0. Add STEP_VERIFY_ALL back to allow skipping
     * it again.
     *
     * @var string[]
     */
    public const SKIPPABLE_STEPS = [];

    /** A Tier 0 task in one of these is with FCH, not waiting on the borrower. */
    private const SUBMITTED_STATUSES = [
        BorrowerTaskVerificationRequest::PROCESSING,
        BorrowerTaskVerificationRequest::COMPLETED,
    ];

    /**
     * Work out the borrower's step and keep their walkthrough row's
     * `current_step` in step with it.
     *
     * @return array{flow: string, current_step: ?string, completed_steps: int, total_steps: int, results_ready: bool, pending_steps: string[], selected_path: ?string}
     */
    public function resolve(Borrower $borrower, bool $profileComplete): array
    {
        return $this->evaluate($borrower, $profileComplete, $this->progress($borrower));
    }

    /**
     * @return array{flow: string, current_step: ?string, completed_steps: int, total_steps: int, results_ready: bool, pending_steps: string[], selected_path: ?string}
     */
    private function evaluate(Borrower $borrower, bool $profileComplete, WalkthroughProgress $progress): array
    {
        return $this->isFreeTrial($borrower)
            ? $this->evaluateFreeTrial($borrower, $profileComplete, $progress)
            : $this->evaluatePaid($borrower, $profileComplete, $progress);
    }

    /**
     * @return array{flow: string, current_step: ?string, completed_steps: int, total_steps: int, results_ready: bool, pending_steps: string[], selected_path: ?string}
     */
    private function evaluatePaid(Borrower $borrower, bool $profileComplete, WalkthroughProgress $progress): array
    {
        $started = $this->hasPaid($borrower);

        $completedSteps = 0;
        $currentStep = null;
        $resultsReady = false;
        $pendingSteps = [];

        if ($started) {
            $creditProfileVerified = (bool) $borrower->credit_profile_updated;
            $tier0Verified = $borrower->hasCompletedTier0();
            $tier0Submitted = $tier0Verified || $this->hasSubmittedTier0($borrower);

            // Really done, skips aside.
            $done = [
                self::STEP_COMPLETE_PROFILE => $profileComplete,
                // An admin can attach a credit profile without a borrower request.
                self::STEP_CREDIT_PROFILE => $creditProfileVerified || $this->hasSubmittedCreditProfile($borrower),
                self::STEP_UPLOAD_DOCUMENTS => $this->hasUploadedDocuments($borrower),
                self::STEP_VERIFY_ALL => $tier0Submitted,
                self::STEP_RESULTS_READY => $progress->completed_at !== null,
            ];

            // Done or set aside for now: the borrower moves past these.
            $passed = [
                ...$done,
                self::STEP_VERIFY_ALL => $done[self::STEP_VERIFY_ALL] || $this->hasSkipped($progress, self::STEP_VERIFY_ALL),
            ];

            $position = 0;
            foreach (self::STEPS as $step) {
                if (!$passed[$step]) {
                    break;
                }
                $position++;
            }

            $currentStep = self::STEPS[$position] ?? self::STEP_COMPLETED;
            // The borrower's part is really done (no skips): credit profile in,
            // every Tier 0 task submitted, every document uploaded. FCH's
            // verification is not waited for.
            $resultsReady = $done[self::STEP_CREDIT_PROFILE]
                && $done[self::STEP_VERIFY_ALL]
                && $done[self::STEP_UPLOAD_DOCUMENTS];
            $completedSteps = count(array_filter($done));
            $pendingSteps = array_values(array_filter(
                $progress->skipped_steps ?? [],
                fn (string $step) => isset($done[$step]) && !$done[$step] && $this->hasSkipped($progress, $step)
            ));
        }

        $this->keepCurrentStep($progress, $currentStep);

        return [
            'flow' => self::FLOW_PAID,
            'current_step' => $currentStep,
            'completed_steps' => $completedSteps,
            'total_steps' => count(self::STEPS),
            // Whether the borrower's part is done, so the results can be shown.
            'results_ready' => $resultsReady,
            // Skipped steps not yet really done, in the order they were skipped.
            'pending_steps' => $pendingSteps,
            // What the borrower chose once their results were ready (PATHS), if anything yet.
            'selected_path' => $progress->selected_path,
        ];
    }

    /**
     * @return array{flow: string, current_step: ?string, completed_steps: int, total_steps: int, results_ready: bool, pending_steps: string[], selected_path: ?string}
     */
    private function evaluateFreeTrial(Borrower $borrower, bool $profileComplete, WalkthroughProgress $progress): array
    {
        // No payment needed: the journey starts straight away.
        $done = [
            self::STEP_COMPLETE_PROFILE => $profileComplete,
            // An admin can attach a credit profile without a borrower request.
            self::STEP_CREDIT_PROFILE => (bool) $borrower->credit_profile_updated || $this->hasSubmittedCreditProfile($borrower),
        ];

        $completedSteps = 0;
        foreach (self::FREE_TRIAL_STEPS as $step) {
            if (!$done[$step]) {
                break;
            }
            $completedSteps++;
        }

        // Done with the credit profile, done with the walkthrough.
        $currentStep = self::FREE_TRIAL_STEPS[$completedSteps] ?? self::STEP_COMPLETED;

        $this->keepCurrentStep($progress, $currentStep);

        return [
            'flow' => self::FLOW_FREE_TRIAL,
            'current_step' => $currentStep,
            'completed_steps' => $completedSteps,
            'total_steps' => count(self::FREE_TRIAL_STEPS),
            // No review in this journey.
            'results_ready' => false,
            'pending_steps' => [],
            // Nothing to choose: there are no results on the free trial.
            'selected_path' => null,
        ];
    }

    /** Only written when it changes, and no row at all for a borrower who hasn't started. */
    private function keepCurrentStep(WalkthroughProgress $progress, ?string $currentStep): void
    {
        if ($progress->current_step !== $currentStep && ($progress->exists || $currentStep !== null)) {
            $progress->current_step = $currentStep;
            $progress->save();
        }
    }

    /** Skipped, and still a step that may be skipped: a skip saved before a step stopped being skippable doesn't count. */
    private function hasSkipped(WalkthroughProgress $progress, string $step): bool
    {
        return in_array($step, self::SKIPPABLE_STEPS, true) && $progress->hasSkipped($step);
    }

    /**
     * Skip the step the borrower is on. Only SKIPPABLE_STEPS, and only the
     * current one, so a stale page can't skip something further along.
     *
     * @return array{flow: string, current_step: ?string, completed_steps: int, total_steps: int, results_ready: bool, pending_steps: string[], selected_path: ?string}
     *
     * @throws ValidationException
     */
    public function skip(Borrower $borrower, bool $profileComplete, string $step): array
    {
        $progress = $this->progress($borrower);
        $onboarding = $this->evaluate($borrower, $profileComplete, $progress);

        if (!in_array($step, self::SKIPPABLE_STEPS, true) || $onboarding['current_step'] !== $step) {
            throw ValidationException::withMessages(['step' => __('borrower.onboarding_step_not_skippable')]);
        }

        $progress->skipped_steps = array_values(array_unique([...($progress->skipped_steps ?? []), $step]));
        $progress->save();

        return $this->evaluate($borrower, $profileComplete, $progress);
    }

    /**
     * The borrower went back to a step they skipped: it stops being skipped,
     * so, not being done, it is the current step again. Only a skipped step
     * still to do (one in `pending_steps`) can be gone back to.
     *
     * @return array{flow: string, current_step: ?string, completed_steps: int, total_steps: int, results_ready: bool, pending_steps: string[], selected_path: ?string}
     *
     * @throws ValidationException
     */
    public function resume(Borrower $borrower, bool $profileComplete, string $step): array
    {
        $progress = $this->progress($borrower);
        $onboarding = $this->evaluate($borrower, $profileComplete, $progress);

        if (!in_array($step, $onboarding['pending_steps'], true)) {
            throw ValidationException::withMessages(['step' => __('borrower.onboarding_step_not_resumable')]);
        }

        $progress->skipped_steps = array_values(array_diff($progress->skipped_steps ?? [], [$step]));
        $progress->save();

        return $this->evaluate($borrower, $profileComplete, $progress);
    }

    /**
     * The borrower chose what they are looking for (one of PATHS). Only on
     * the last step of the paid journey with the results really ready - not
     * on reaching it by skipping, when there is nothing to choose from yet.
     * The choice can be changed; the latest one is kept, with its time.
     *
     * @return array{flow: string, current_step: ?string, completed_steps: int, total_steps: int, results_ready: bool, pending_steps: string[], selected_path: ?string}
     *
     * @throws ValidationException
     */
    public function choosePath(Borrower $borrower, bool $profileComplete, string $path): array
    {
        $progress = $this->progress($borrower);
        $onboarding = $this->evaluate($borrower, $profileComplete, $progress);

        $choosable = in_array($onboarding['current_step'], [self::STEP_RESULTS_READY, self::STEP_COMPLETED], true)
            && $onboarding['results_ready'];

        if (!in_array($path, self::PATHS, true) || !$choosable) {
            throw ValidationException::withMessages(['path' => __('borrower.onboarding_path_not_available')]);
        }

        $progress->selected_path = $path;
        $progress->path_selected_at = now();
        $progress->save();

        return $this->evaluate($borrower, $profileComplete, $progress);
    }

    /**
     * The portal showed the borrower their results (the last step). Finishes
     * the journey if they are on the last step and every earlier step really
     * is done (credit profile, Tier 0 and documents in, nothing skipped left
     * to do); otherwise nothing changes. Safe to repeat.
     * The free-trial journey finishes by itself with the credit profile.
     *
     * @return array{flow: string, current_step: ?string, completed_steps: int, total_steps: int, results_ready: bool, pending_steps: string[], selected_path: ?string}
     */
    public function complete(Borrower $borrower, bool $profileComplete): array
    {
        $progress = $this->progress($borrower);
        $onboarding = $this->evaluate($borrower, $profileComplete, $progress);

        $finishable = $onboarding['current_step'] === self::STEP_RESULTS_READY && $onboarding['results_ready'];

        if (!$finishable) {
            return $onboarding;
        }

        // On the last step completed_at is still empty, so the first time is kept.
        $progress->completed_at = now();
        $progress->save();

        return $this->evaluate($borrower, $profileComplete, $progress);
    }

    private function progress(Borrower $borrower): WalkthroughProgress
    {
        return WalkthroughProgress::for(
            $borrower,
            $this->isFreeTrial($borrower)
                ? WalkthroughProgress::BORROWER_FREE_TRIAL
                : WalkthroughProgress::BORROWER_ONBOARDING
        );
    }

    /** A free-trial borrower gets only the start of the walkthrough; the rest is for paid borrowers. */
    private function isFreeTrial(Borrower $borrower): bool
    {
        return (bool) $borrower->is_free_trail;
    }

    /**
     * A document, not rejected, in every required category. With no required
     * categories set up, any document will do.
     */
    private function hasUploadedDocuments(Borrower $borrower): bool
    {
        $documents = BorrowerDocument::where('borrower_id', $borrower->getKey())
            ->where(fn ($query) => $query
                ->whereNull('status')
                ->orWhere('status', '!=', BorrowerDocument::STATUS_REJECTED));

        $required = BorrowerDocType::required()->pluck('id');
        if ($required->isEmpty()) {
            return $documents->exists();
        }

        $uploaded = $documents->whereIn('document_type_id', $required)
            ->distinct()
            ->pluck('document_type_id');

        return $required->diff($uploaded)->isEmpty();
    }

    /**
     * Mirrors the borrower portal's isSubscriptionRestricted(): a white-label or
     * certified borrower needs their enrollment fee covered, and a subscription
     * on record has to be active and within its paid period. No subscription
     * record means nothing to hold the borrower to.
     */
    private function hasPaid(Borrower $borrower): bool
    {
        $partnerType = $borrower->partner?->partner_type;
        $partnerPaysForMe = $borrower->partner_payment_for_me ?? true;

        if (
            in_array($partnerType, [Partner::PARTNER_TYPE_WHITE_LABEL, Partner::PARTNER_TYPE_CERTIFIED], true)
            && !$partnerPaysForMe
        ) {
            return false;
        }

        $subscription = Subscription::where('entity_type', Borrower::class)
            ->where('entity_id', $borrower->getKey())
            ->first();

        if (!$subscription) {
            return true;
        }

        $status = strtolower(trim((string) $subscription->status));
        if ($status !== '' && $status !== strtolower(Subscription::STATUS_ACTIVE)) {
            return false;
        }

        if ($subscription->subscription_end_date) {
            $endDate = Carbon::parse($subscription->subscription_end_date)->startOfDay();
            if (today()->greaterThan($endDate)) {
                return false;
            }
        }

        return true;
    }

    private function hasSubmittedCreditProfile(Borrower $borrower): bool
    {
        return CreditProfileRequest::where('borrower_id', $borrower->getKey())->exists();
    }

    /**
     * Every Tier 0 task's latest request is in review or verified, the way the
     * Step-By-Step Guide shows them. A task never submitted, or rejected, still
     * needs the borrower.
     */
    private function hasSubmittedTier0(Borrower $borrower): bool
    {
        $tier0 = $this->tier0();
        if (!$tier0) {
            return false;
        }

        $taskIds = TierTask::where('tier_id', $tier0->id)->pluck('id');
        if ($taskIds->isEmpty()) {
            return false;
        }

        $latestStatuses = BorrowerTaskVerificationRequest::where('borrower_id', $borrower->getKey())
            ->where('tier_id', $tier0->id)
            ->whereNull('migrated_task_id')
            ->whereIn('task_id', $taskIds)
            ->orderBy('id')
            ->get(['task_id', 'status'])
            ->keyBy('task_id')
            ->map(fn ($request) => $request->status);

        return $taskIds->every(
            fn ($id) => in_array($latestStatuses->get($id), self::SUBMITTED_STATUSES, true)
        );
    }

    private function tier0(): ?Tier
    {
        return Tier::foundation();
    }
}
