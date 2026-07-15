<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\WeeklySummary;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\FCH\Admin\WeeklySummary\WeeklySummaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class WeeklySummaryController extends Controller
{
    public function __construct(protected WeeklySummaryService $service) {}

    /**
     * GET /v1/admin/weekly-summary
     *
     * Query params:
     *   start_date  (Y-m-d, defaults to last Monday)
     *   end_date    (Y-m-d, defaults to last Sunday)
     */
    public function index(Request $request)
    {
        try {
            // Default to the most recently completed Mon–Sun week
            $endDate   = isset($request->end_date)
                ? Carbon::parse($request->end_date)->endOfDay()
                : Carbon::now()->startOfWeek(Carbon::MONDAY)->subDay()->endOfDay(); // last Sunday

            $startDate = isset($request->start_date)
                ? Carbon::parse($request->start_date)->startOfDay()
                : $endDate->copy()->subDays(6)->startOfDay(); // last Monday

            $data = $this->service->getSummary($startDate, $endDate);

            return ApiResponse::success($data, 'Weekly summary retrieved successfully.');
        } catch (\Throwable $e) {
            return ApiResponse::serverError('Failed to retrieve weekly summary: ' . $e->getMessage());
        }
    }
}
