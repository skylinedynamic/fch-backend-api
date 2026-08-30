<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Statistics;

use App\Http\Controllers\Controller;
use App\Services\FCH\Common\Statistics\StatisticsService;
use App\Helpers\ApiResponse;

class StatisticsController extends Controller
{
    public function __construct(protected StatisticsService $statisticsService) {}

    /**
     * Get Statistics
     */
    public function stats()
    {
        $data = $this->statisticsService->getPartnerProfileStats();
        return ApiResponse::success($data, __('common.stats_retrieved'));
    }
}
