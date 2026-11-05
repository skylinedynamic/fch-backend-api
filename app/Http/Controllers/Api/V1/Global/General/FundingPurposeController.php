<?php

namespace App\Http\Controllers\Api\V1\Global\General;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Global\General\FundingPurposeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The funding application's Purpose of Funds list, for the admin, partner and
 * borrower wizards - the same list the embed form shows, so no portal keeps its
 * own copy. Public reference data, like common/locations: not sensitive.
 */
class FundingPurposeController extends Controller
{
    public function __construct(private FundingPurposeService $service)
    {
    }

    /** GET /api/v1/common/funding-purposes - the purposes, in their order. */
    public function index(): JsonResponse
    {
        try {
            return ApiResponse::success($this->service->purposes(), __('application.funding_purposes_fetched'));
        } catch (Throwable $e) {
            Log::error('Funding purpose list failed: ' . $e->getMessage());

            return ApiResponse::serverError(__('application.funding_purposes_fetch_failed'));
        }
    }
}
