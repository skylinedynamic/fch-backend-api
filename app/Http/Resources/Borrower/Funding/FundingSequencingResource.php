<?php

namespace App\Http\Resources\Borrower\Funding;

use App\Models\Funding\FundingSequencing;
use App\Models\FundingRequest\FundingRequest;
use App\Models\User\User;
use App\Services\Borrower\Funding\FundingReadinessService;
use Illuminate\Http\Resources\Json\JsonResource;

class FundingSequencingResource extends JsonResource
{
    public function toArray($request)
    {
        $requirements = [];

        if (!is_null($this->min_fico)) {
            $requirements[] = ['label' => 'Min FICO', 'value' => (string) $this->min_fico];
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
        if (auth()->check() && auth()->user() && auth()->user()->user_type === User::BORROWER && auth()->user()->borrower) {
            $borrower = auth()->user()->borrower;
        }

        $readinessService = app(FundingReadinessService::class);
        $missingDocumentsList = $readinessService->getMissingDocuments($this->resource, $borrower);
        
        $qualificationService = app(\App\Services\FCH\Common\Funding\FundingQualificationService::class);
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
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'subtitle' => $this->subtitle,
            'icon' => $this->icon,
            'icon_color' => $this->icon_color,
            'description' => $this->description,
            'amount_range' => $this->amount_range,
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
            // Dynamic engine fields
            'engine_status' => $this->engine_status ?? FundingSequencing::ENGINE_STATUS_ACTIVE,
            'engine_status_label' => $this->engine_status_label ?? FundingSequencing::ENGINE_LABEL_ACTIVE,
        ];
    }
}
