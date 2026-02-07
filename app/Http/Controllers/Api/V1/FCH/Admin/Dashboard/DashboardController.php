<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Dashboard;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\FCH\Admin\Dashboard\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    protected $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    /**
     * Get Dashboard Stats and Lists
     */
    public function index(Request $request)
    {
        try {
            $data = $this->dashboardService->getStats();
            return ApiResponse::success($data, __('dashboard.success.stats_retrieved'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('dashboard.error.retrieval_failed') . ': ' . $e->getMessage());
        }
    }
}
