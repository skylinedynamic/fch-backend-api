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
        $estimatedAccess = '$0';
        $breakdown = [
            ['label' => 'Business Credit Cards', 'value' => '$0'],
            ['label' => 'Unsecured Lines of Credit', 'value' => '$0'],
        ];

        if ($score >= 90) {
            $estimatedAccess = '$250,000+';
            $breakdown = [
                ['label' => 'Business Credit Cards', 'value' => '$100,000+'],
                ['label' => 'Unsecured Lines of Credit', 'value' => '$150,000+'],
            ];
        } elseif ($score >= 80) {
            $estimatedAccess = '$150,000+';
            $breakdown = [
                ['label' => 'Business Credit Cards', 'value' => '$50,000+'],
                ['label' => 'Unsecured Lines of Credit', 'value' => '$100,000+'],
            ];
        } elseif ($score >= 70) {
            $estimatedAccess = '$75,000+';
            $breakdown = [
                ['label' => 'Business Credit Cards', 'value' => '$25,000+'],
                ['label' => 'Unsecured Lines of Credit', 'value' => '$50,000+'],
            ];
        } elseif ($score >= 60) {
            $estimatedAccess = '$35,000+';
            $breakdown = [
                ['label' => 'Business Credit Cards', 'value' => '$15,000+'],
                ['label' => 'Unsecured Lines of Credit', 'value' => '$20,000+'],
            ];
        }

        $data = [
            'readiness_score' => $score,
            'readiness_status' => $status,
            'readiness_status_color' => $result['score_label_color'],
            'factors' => $result['factors'],
            'score_labels_top' => [
                'min' => 501,
                'max' => 900,
            ],
            'score_labels_bottom' => [
                'min' => 300,
                'max' => 850,
            ],
            'contributing_factors' => $contributingFactors,
            'estimated_total_access' => $estimatedAccess,
            'breakdown' => $breakdown,
        ];

        return ApiResponse::success(
            $data,
            __('credit_builder.capital_access_overview_fetched')
        );
    }
}
