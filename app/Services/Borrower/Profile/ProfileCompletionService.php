<?php

namespace App\Services\Borrower\Profile;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerBankAccount;
use App\Models\Borrower\CreditProfileRequest;
use App\Models\User\User;
use App\Models\Company\Company;
use Illuminate\Support\Facades\Auth;

class ProfileCompletionService
{
    public function __construct(protected OnboardingStepService $onboarding)
    {
    }

    /**
     * Check if the borrower's profile is complete (personal, business, financial required fields).
     * Returns overall status and which fields are missing per section.
     *
     * The required fields are the ones each profile tab marks required (*) in
     * the borrower portal, which its save request also requires
     * (UpdatePersonalProfileRequest, UpdateBusinessInfoRequest,
     * UpdateFinancialInfoRequest). Nothing is required here that the tab
     * gives no way to fill in.
     */
    public function checkCompletion(): array
    {
        $user = Auth::user();
        $borrower = Borrower::where('id', $user->entity_id)->first();

        $personal = $this->checkPersonal($borrower);
        $business = $this->checkBusiness($borrower);
        $financial = $this->checkFinancial($borrower);

        $isComplete = $personal['is_complete'] && $business['is_complete'] && $financial['is_complete'];

        return [
            'is_complete' => $isComplete,
            'personal' => $personal,
            'business' => $business,
            'financial' => $financial,
            // The borrower portal's "what to do next" suggestion.
            'onboarding' => $this->onboarding->resolve($borrower, $isComplete),
            // Whether Credit Card Stacking is open, and what it is waiting on.
            'credit_card_stacking' => [
                'unlocked' => $borrower->hasUnlockedCreditCardStacking(),
                'credit_profile_verified' => $borrower->hasVerifiedCreditScore(),
                'tier_0_completed' => $borrower->hasCompletedTier0(),
            ],
            'funding' => $this->fundingAccess($borrower),
        ];
    }

    /**
     * Whether the Funding page is open. It waits for FCH to upload the
     * borrower's credit report: without it every funding criterion reads
     * "Failed". `credit_profile_submitted` says whether the borrower has done
     * their part (the credit profile form), so the portal can say what is
     * still to come.
     *
     * @return array{unlocked: bool, credit_profile_submitted: bool}
     */
    private function fundingAccess(Borrower $borrower): array
    {
        $reportUploaded = $borrower->hasVerifiedCreditScore();

        return [
            'unlocked' => $reportUploaded,
            'credit_profile_submitted' => $reportUploaded
                || CreditProfileRequest::where('borrower_id', $borrower->getKey())->exists(),
        ];
    }

    /**
     * The borrower opened Credit Card Stacking: the last Getting Started step
     * once their results are ready (see OnboardingStepService::complete()).
     *
     * @return array{flow: string, current_step: ?string, completed_steps: int, total_steps: int, results_ready: bool, pending_steps: string[], selected_path: ?string}
     */
    public function completeOnboarding(): array
    {
        $borrower = Borrower::where('id', Auth::user()->entity_id)->first();

        return $this->onboarding->complete($borrower, $this->isProfileComplete($borrower));
    }

    /**
     * The borrower skipped the Getting Started step they are on (see
     * OnboardingStepService::skip()).
     *
     * @return array{flow: string, current_step: ?string, completed_steps: int, total_steps: int, results_ready: bool, pending_steps: string[], selected_path: ?string}
     */
    public function skipOnboardingStep(string $step): array
    {
        $borrower = Borrower::where('id', Auth::user()->entity_id)->first();

        return $this->onboarding->skip($borrower, $this->isProfileComplete($borrower), $step);
    }

    /**
     * The borrower went back to a Getting Started step they skipped (see
     * OnboardingStepService::resume()).
     *
     * @return array{flow: string, current_step: ?string, completed_steps: int, total_steps: int, results_ready: bool, pending_steps: string[], selected_path: ?string}
     */
    public function resumeOnboardingStep(string $step): array
    {
        $borrower = Borrower::where('id', Auth::user()->entity_id)->first();

        return $this->onboarding->resume($borrower, $this->isProfileComplete($borrower), $step);
    }

    /**
     * Getting Started: the borrower chose what they are looking for once
     * their results were ready (one of OnboardingStepService::PATHS; see
     * OnboardingStepService::choosePath()).
     *
     * @return array{flow: string, current_step: ?string, completed_steps: int, total_steps: int, results_ready: bool, pending_steps: string[], selected_path: ?string}
     */
    public function chooseOnboardingPath(string $path): array
    {
        $borrower = Borrower::where('id', Auth::user()->entity_id)->first();

        return $this->onboarding->choosePath($borrower, $this->isProfileComplete($borrower), $path);
    }

    private function isProfileComplete($borrower): bool
    {
        return $this->checkPersonal($borrower)['is_complete']
            && $this->checkBusiness($borrower)['is_complete']
            && $this->checkFinancial($borrower)['is_complete'];
    }

    private function checkPersonal($borrower): array
    {
        $missing = [];

        $fields = [
            'first_name' => $borrower->first_name,
            'last_name' => $borrower->last_name,
            'email' => $borrower->email,
            'phone_number' => $borrower->phone_number,
            'city' => $borrower->city,
            'state' => $borrower->state,
            'zip_code' => $borrower->postal_code,
            'country' => $borrower->country,
            'home_address' => $borrower->home_address,
            'last_4_ssn' => $borrower->last_4_ssn,
            'date_of_birth' => $borrower->date_of_birth,
        ];
        foreach ($fields as $key => $value) {
            $this->requireField($missing, $key, $value);
        }

        return [
            'is_complete' => empty($missing),
            'missing_fields' => $missing,
        ];
    }

    /**
     * The Business Details section: complete, and which fields are missing.
     * Also the rule for the Capital Access pre-flight "Business Information"
     * item (BUG-043), so the two never disagree.
     */
    public function checkBusiness($borrower): array
    {
        $missing = [];

        $fields = [
            'business_name' => $borrower->business_name,
            // The EIN is optional (BUG-054): a profile is complete without it.
            // 'business_phone' => $borrower->business_phone,
            // Required by the Business Details form (UpdateBusinessInfoRequest).
            'business_email' => $borrower->business_email,
            'business_address' => $borrower->business_address,
            'business_country' => $borrower->business_country,
            'entity_type' => $borrower->business_entity_type,
            'industry_type' => $borrower->business_industry_type,
            'state' => $borrower->business_state,
            'city' => $borrower->business_city,
            'zip_code' => $borrower->business_postal_code,
            'business_start_date' => $borrower->business_start_date,

        ];
        foreach ($fields as $key => $value) {
            $this->requireField($missing, $key, $value);
        }

        // Choosing "Other" asks for the details (required_if on save).
        if ($borrower->business_entity_type === config('borrower.entity_type_other')) {
            $this->requireField($missing, 'entity_type_details', $borrower->business_entity_type_note);
        }
        if ($borrower->business_industry_type === config('borrower.industry_type_other')) {
            $this->requireField($missing, 'industry_type_details', $borrower->business_industry_type_note);
        }

        return [
            'is_complete' => empty($missing),
            'missing_fields' => $missing,
        ];
    }

    private function checkFinancial($borrower): array
    {
        $missing = [];

        // Years in business isn't here: the Financial tab has no field for it.
        // Nor is currency: the tab starts on USD, so there is nothing to ask
        // for; it is still required, and saved, when the tab is. Annual
        // personal income and average monthly deposits are optional.
        $fields = [
            'monthly_revenue' => $borrower->monthly_revenue,
            'annual_revenue' => $borrower->annual_revenue,
        ];

        foreach ($fields as $key => $value) {
            $this->requireField($missing, $key, $value);
        }

        // Bank accounts are optional, but each one added needs its bank name.
        $unnamedBankAccount = BorrowerBankAccount::where('borrower_id', $borrower->id)
            ->where(fn ($query) => $query->whereNull('bank_name')->orWhereRaw("TRIM(bank_name) = ''"))
            ->exists();
        if ($unnamedBankAccount) {
            $missing[] = 'bank_name';
        }

        return [
            'is_complete' => empty($missing),
            'missing_fields' => $missing,
        ];
    }

    private function requireField(array &$missing, string $key, mixed $value): void
    {
        if ($value === null) {
            $missing[] = $key;
            return;
        }
        if (is_string($value) && trim($value) === '') {
            $missing[] = $key;
            return;
        }
    }

}