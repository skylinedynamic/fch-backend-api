<?php

namespace App\Http\Resources\FCH\Funding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FundingSequencingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'subtitle' => $this->subtitle,
            'description' => $this->description,
            'amount_range' => $this->amount_range,
            'terms' => $this->terms,
            'icon' => $this->icon,
            'icon_color' => $this->icon_color,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'action_url' => $this->action_url,
            'funding_group_id' => $this->funding_group_id,
            'funding_type_id' => $this->funding_type_id,
            'speed_to_funds' => $this->speed_to_funds,
            'uses' => $this->uses ?? [],
            'readiness_factors' => $this->readiness_factors ?? [],
            'lender_product_category_id' => $this->lender_product_category_id,
            'missing_documents' => $this->missing_documents ?? [],
            'min_fico' => $this->min_fico,
            'min_business_credit_score' => $this->min_business_credit_score,
            'min_tib_months' => $this->min_tib_months,
            'min_monthly_revenue' => $this->min_monthly_revenue,
            'min_monthly_deposits' => $this->min_monthly_deposits,
            'min_average_daily_balance' => $this->min_average_daily_balance,
            'max_nsf_count' => $this->max_nsf_count,
            'max_negative_days' => $this->max_negative_days,
            'min_dscr' => $this->min_dscr,
            'allowed_states' => $this->allowed_states ?? [],
            'restricted_states' => $this->restricted_states ?? [],
            'allowed_industries' => $this->allowed_industries ?? [],
            'restricted_industries' => $this->restricted_industries ?? [],
            'require_completed_application' => $this->require_completed_application,
            'require_documents_uploaded' => $this->require_documents_uploaded,
            'allowed_documents' => $this->allowed_documents ?? [],
            'manual_review_allowed' => $this->manual_review_allowed,
            'exception_allowed' => $this->exception_allowed,
            'group' => $this->whenLoaded('group', function () {
                return [
                    'id' => $this->group->id,
                    'name' => $this->group->name,
                ];
            }),
            'type' => $this->whenLoaded('type', function () {
                return [
                    'id' => $this->type->id,
                    'name' => $this->type->name,
                ];
            }),
            'lender_visibilities' => $this->whenLoaded('lenderVisibilities', function () {
                return $this->lenderVisibilities->map(function ($lfs) {
                    return array_merge(
                        $lfs->toArray(),
                        ['lender' => $lfs->lender ? ['id' => $lfs->lender->id, 'name' => $lfs->lender->name] : null]
                    );
                });
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
