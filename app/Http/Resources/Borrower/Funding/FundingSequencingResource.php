<?php

namespace App\Http\Resources\Borrower\Funding;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Funding\FundingSequencing;
use App\Models\FundingRequest\FundingRequest;
use App\Models\User\User;
use App\Services\Borrower\Funding\FundingReadinessService;
use App\Services\FCH\Common\Funding\FundingQualificationService;
use Illuminate\Http\Resources\Json\JsonResource;

class FundingSequencingResource extends JsonResource
{
    public function toArray($request)
    {
        $requirements = [];

        if (!is_null($this->min_fico)) {
            $requirements[] = ['label' => 'Min FICO', 'value' => (string) $this->min_fico];
        }
        if (!is_null($this->min_business_credit_score)) {
            $requirements[] = ['label' => 'Min Business Credit', 'value' => (string) $this->min_business_credit_score];
        }
        if (!is_null($this->min_tib_months)) {
            $requirements[] = ['label' => 'Min Time in Biz', 'value' => $this->min_tib_months . ' months'];
        }
        if (!is_null($this->min_monthly_revenue)) {
            $requirements[] = ['label' => 'Min Monthly Revenue', 'value' => '$' . number_format($this->min_monthly_revenue)];
        }
        if (!is_null($this->min_monthly_deposits)) {
            $requirements[] = ['label' => 'Min Monthly Deposits', 'value' => '$' . number_format($this->min_monthly_deposits)];
        }
        if (!is_null($this->min_average_daily_balance)) {
            $requirements[] = ['label' => 'Min Avg Daily Balance', 'value' => '$' . number_format($this->min_average_daily_balance)];
        }
        if ($this->max_nsf_count !== null) {
            $requirements[] = ['label' => 'Max NSF', 'value' => (string) $this->max_nsf_count];
        }
        if ($this->max_negative_days !== null) {
            $requirements[] = ['label' => 'Negative Days', 'value' => (string) $this->max_negative_days];
        }
        if (!is_null($this->min_dscr)) {
            $requirements[] = ['label' => 'Min DSCR', 'value' => (string) $this->min_dscr];
        }
        if ($this->require_completed_application) {
            $requirements[] = ['label' => 'App Required', 'value' => 'Yes'];
        }
        if ($this->require_documents_uploaded) {
            $requirements[] = ['label' => 'Docs Required', 'value' => 'Yes'];
        }
        if ($this->manual_review_allowed) {
            $requirements[] = ['label' => 'Manual Review', 'value' => 'Allowed'];
        }
        if ($this->exception_allowed) {
            $requirements[] = ['label' => 'Exceptions', 'value' => 'Allowed'];
        }

        $formatArray = function ($arr) {
            if (empty($arr)) return null;
            if (is_string($arr)) {
                $decoded = json_decode($arr, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $arr = $decoded;
                }
            }
            if (is_array($arr) && count($arr) > 0) {
                return implode(', ', $arr);
            }
            return null;
        };

        if ($val = $formatArray($this->allowed_states)) {
            $requirements[] = ['label' => 'Allowed States', 'value' => $val];
        }
        if ($val = $formatArray($this->restricted_states)) {
            $requirements[] = ['label' => 'Restricted States', 'value' => $val];
        }
        if ($val = $formatArray($this->allowed_industries)) {
            $requirements[] = ['label' => 'Allowed Industries', 'value' => $val];
        }
        if ($val = $formatArray($this->restricted_industries)) {
            $requirements[] = ['label' => 'Restricted Industries', 'value' => $val];
        }

        $borrower = null;
        if (request()->has('borrower_id') && auth()->check() && in_array(auth()->user()->user_type, [\App\Models\User\User::ADMIN, \App\Models\User\User::EMPLOYEE, \App\Models\User\User::PARTNER])) {
            $borrower = Borrower::find(request()->get('borrower_id'));
        } elseif (auth()->check() && auth()->user() && auth()->user()->user_type === User::BORROWER && auth()->user()->borrower) {
            $borrower = auth()->user()->borrower;
        }

        $readinessService = app(FundingReadinessService::class);
        $missingDocumentsList = $readinessService->getMissingDocuments($this->resource, $borrower);
        
        $qualificationService = app(FundingQualificationService::class);
        $formattedReadinessFactors = $qualificationService->evaluateReadinessFactors($borrower, $this->id);

        $engineStatus = $this->engine_status ?? FundingSequencing::ENGINE_STATUS_ACTIVE;
        if ($engineStatus === FundingSequencing::ENGINE_STATUS_HIDDEN && $borrower) {
            $hardStopReasons = $this->getEngineStatusReasonsForBorrower($borrower);
            foreach ($hardStopReasons as $reason) {
                // Check if already in factors
                $exists = false;
                foreach ($formattedReadinessFactors as $factor) {
                    if ($factor['label'] === $reason['label']) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $formattedReadinessFactors[] = $reason;
                }
            }
        }

        $latestRequestedAmount = null;
        $latestUseOfFunds = null;
        $latestReviewStatus = null;
        $hasFundingApplication = false;
        $latestApplication = null;

        if ($borrower) {
            $latestReview = FundingRequest::where('borrower_id', $borrower->id)
                ->where('funding_sequence_id', $this->id)
                ->orderBy('id', 'desc')
                ->first();
            
            if ($latestReview) {
                $latestRequestedAmount = $latestReview->requested_amount;
                $latestUseOfFunds = $latestReview->use_of_funds;
                $latestReviewStatus = $latestReview->status;
            }

            if ($latestReview) {
                // Fetch the application rather than just testing for one: the card
                // needs its status to decide between "View Application" and
                // "Start a New Funding Request".
                $latestApplication = FundingApplication::where('funding_request_id', $latestReview->id)
                    ->latest('id')
                    ->first();

                if (!$latestApplication) {
                    $approvedFundingName = $this->name ?: ($this->type ? $this->type->name : null);
                    if ($approvedFundingName) {
                        $latestApplication = FundingApplication::where('borrower_id', $borrower->id)
                            ->where('approved_funding', $approvedFundingName)
                            ->where('created_at', '>=', $latestReview->created_at)
                            ->latest('id')
                            ->first();
                    }
                }

                $hasFundingApplication = (bool) $latestApplication;
            }
        }

        $formatRange = function ($range) {
            if (empty($range)) return null;
            $parts = explode('-', $range);
            if (count($parts) === 2) {
                return currencyFormat(trim($parts[0]), 0) . ' - ' . currencyFormat(trim($parts[1]), 0);
            }
            return currencyFormat(trim($range), 0);
        };

        return [
            'id' => $this->id,
            'name' => $this->name,
            'subtitle' => $this->subtitle,
            'icon' => $this->icon,
            'icon_color' => $this->icon_color,
            'description' => $this->description,
            'amount_range' => $formatRange($this->amount_range),
            'terms' => $this->terms,
            'speed_to_funds' => $this->speed_to_funds,
            'uses' => $this->uses,
            'readiness_factors' => $formattedReadinessFactors,
            'missing_documents' => $missingDocumentsList,
            'allowed_documents' => $this->allowed_documents ?? [],
            'action_url' => $this->action_url,
            'funding_type_id' => $this->funding_type_id,
            'funding_type_name' => $this->type ? $this->type->name : null,
            'funding_group_id' => $this->funding_group_id,
            'require_documents_uploaded' => (bool)$this->require_documents_uploaded,
            'requirements' => $requirements,
            'latest_requested_amount' => $latestRequestedAmount,
            'latest_use_of_funds' => $latestUseOfFunds,
            'latest_review_status' => $latestReviewStatus,
            'has_funding_application' => $hasFundingApplication,
            'latest_review_id' => $latestReview ? $latestReview->id : null,

            // The application this funding option already produced, if any, and
            // what the borrower may still do about it. `can_start_new_funding_request`
            // is the one that matters on the detail card: once the application
            // has left the borrower's hands - with a lender, funded, closed or
            // denied - that round is settled, so they may apply again rather than
            // being parked on a "View Application" link that leads nowhere useful.
            'funding_application_id' => $latestApplication ? $latestApplication->id : null,
            'funding_application_status' => $latestApplication ? $latestApplication->status : null,
            'funding_application_is_locked' => $latestApplication ? $latestApplication->isLockedForEdit() : false,
            'funding_application_requires_resign' => $latestApplication ? (bool) $latestApplication->requires_resign : false,
            'can_start_new_funding_request' => $latestApplication ? $latestApplication->allowsNewFundingRequest() : false,
            // Dynamic engine fields
            'engine_status' => $this->engine_status ?? FundingSequencing::ENGINE_STATUS_ACTIVE,
            'engine_status_label' => $this->engine_status_label ?? FundingSequencing::ENGINE_LABEL_ACTIVE,
        ];
    }
}
