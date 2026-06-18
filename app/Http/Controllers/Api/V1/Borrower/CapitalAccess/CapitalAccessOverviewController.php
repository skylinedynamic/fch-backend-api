<?php

namespace App\Http\Controllers\Api\V1\Borrower\CapitalAccess;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CapitalAccessOverviewController extends Controller
{
    /**
     * Get the capital access overview data for the authenticated borrower.
     */
    public function index(): JsonResponse
    {
        $borrower = auth()->user()->borrower;
        if (!$borrower) {
            return ApiResponse::notFound(__('borrower.not_found'));
        }

        // Call the service to calculate readiness score
        $calculationService = app(\App\Services\Borrower\CapitalAccess\ReadinessScoreCalculationService::class);
        $result = $calculationService->calculate($borrower);

        $score = $result['total_score'];
        $status = $result['score_label'];

        // Dynamic contributing factors
        $contributingFactors = [];

        foreach ($result['factors'] as $factor) {
            if ($factor['raw_score'] >= 80) {
                $tier = \App\Models\Common\ScoreConfiguration::where('category', \App\Models\Common\ScoreConfiguration::CATEGORY_SUBSCORE_TIER)
                    ->where('model_slug', 'funding_readiness')
                    ->where('factor_slug', $factor['slug'])
                    ->where('numeric_value', $factor['raw_score'])
                    ->first();
                $detail = $tier ? $tier->label : 'Good';
                $contributingFactors[] = "{$factor['label']}: {$detail}";
            }
        }

        if (empty($contributingFactors)) {
            $contributingFactors = ['Initial Profile Setup'];
        }

        // Access values based on status/score
        $estimatedAccess = '$150000';


        $data = [
            'readiness_score' => $score,
            'readiness_status' => $status,
            'readiness_status_color' => $result['score_label_color'],
            'factors' => $result['factors'],
            'contributing_factors' => $contributingFactors,
            'estimated_total_access' => $estimatedAccess,
        ];

        return ApiResponse::success(
            $data,
            __('credit_builder.capital_access_overview_fetched')
        );
    }
}
