<?php

namespace App\Http\Controllers\Api\V1\Borrower\Dashboard;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Borrower\Dashboard\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    protected $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    /**
     * Get Dashboard Stats
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
