<?php

namespace App\Http\Resources\Borrower\Funding;

use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\BorrowerDocumentsChecklist;
use App\Models\Funding\FundingSequencing;
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
        if (!is_null($this->max_nsf_count)) {
            $requirements[] = ['label' => 'Max NSF Count', 'value' => (string) $this->max_nsf_count];
        }
        if (!is_null($this->max_negative_days)) {
            $requirements[] = ['label' => 'Max Negative Days', 'value' => (string) $this->max_negative_days];
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
        if (auth()->check() && auth()->user() && auth()->user()->user_type === \App\Models\User\User::BORROWER && auth()->user()->borrower) {
            $borrower = auth()->user()->borrower;
        }

        $readinessService = app(FundingReadinessService::class);
        $missingDocumentsList = $readinessService->getMissingDocuments($this, $borrower);
        $formattedReadinessFactors = $readinessService->evaluateFactors($this, $borrower, $missingDocumentsList);

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
            'qualify_reasons' => $this->qualify_reasons,
            'readiness_factors' => $formattedReadinessFactors,
            'missing_documents' => $missingDocumentsList,
            'action_url' => $this->action_url,
            'funding_type_id' => $this->funding_type_id,
            'funding_group_id' => $this->funding_group_id,
            'requirements' => $requirements,
            // Dynamic engine fields
            'engine_status' => $this->engine_status ?? 'Active',
            'engine_status_label' => $this->engine_status_label ?? 'Eligible Now',
        ];
    }
}
