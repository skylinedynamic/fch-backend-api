<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\OwnerSummary;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\OwnerSummary\FetchOwnerSummary;
use App\Services\FCH\Common\OwnerSummary\OwnerSummaryPeriod;
use App\Services\FCH\Common\OwnerSummary\OwnerSummaryService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/common/owner-summary - the host owner's business summary for a
 * period (see OwnerSummaryService for what each number means).
 */
class OwnerSummaryController extends Controller
{
    public function __construct(private readonly OwnerSummaryService $service)
    {
    }

    public function show(FetchOwnerSummary $request): JsonResponse
    {
        $period = OwnerSummaryPeriod::make($request->period(), $request->timezone());

        return ApiResponse::success(
            $this->service->summary($request->user(), $period, $request->environment()),
            __('owner_summary.retrieved')
        );
    }
}
