<?php

namespace App\Http\Controllers\Api\V1\Global\General;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Global\General\LenderProductCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The funding types - one list for every funding type field: Preferred /
 * Additional Funding Types in the admin, partner and borrower wizards (this
 * API) and the embed form (the same service). They are the Active lender
 * product categories, so an application's types are the categories its
 * lenders are matched on. Public reference data, like common/locations: the
 * names are not sensitive.
 */
class LenderProductCategoryController extends Controller
{
    public function __construct(private LenderProductCategoryService $service)
    {
    }

    /** GET /api/v1/common/funding-types - the names, in their order. */
    public function index(): JsonResponse
    {
        try {
            return ApiResponse::success($this->service->fundingTypeNames(), __('lender.category_fetched'));
        } catch (Throwable $e) {
            Log::error('Funding type list failed: ' . $e->getMessage());

            return ApiResponse::serverError(__('lender.category_fetch_failed'));
        }
    }
}
