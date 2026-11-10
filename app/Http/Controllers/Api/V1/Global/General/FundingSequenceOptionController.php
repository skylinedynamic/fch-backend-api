<?php

namespace App\Http\Controllers\Api\V1\Global\General;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Global\General\FetchFundingSequenceOptionsRequest;
use App\Http\Resources\Global\General\FundingSequenceOptionResource;
use App\Services\Global\General\FundingSequenceOptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Funding Sequence dropdown on a funding application's Funding Request
 * step: the sequences offered for its Preferred Funding Type. Any signed-in
 * user - the admin, partner and borrower wizards all show it.
 */
class FundingSequenceOptionController extends Controller
{
    public function __construct(private FundingSequenceOptionService $service) {}

    /** GET /api/v1/common/funding-sequences?funding_type=Term Loan */
    public function index(FetchFundingSequenceOptionsRequest $request): JsonResponse
    {
        try {
            $sequences = $this->service->forFundingType($request->validated('funding_type'));

            return ApiResponse::success(
                FundingSequenceOptionResource::collection($sequences),
                __('funding_sequencing.options_fetched')
            );
        } catch (Throwable $e) {
            Log::error('Funding sequence options failed: '.$e->getMessage());

            return ApiResponse::serverError(__('funding_sequencing.options_fetch_failed'));
        }
    }
}
