<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Partner;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\FCH\Partner\PartnerOptionResource;
use App\Services\FCH\Partner\PartnerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * The partner list behind the host's partner filter dropdowns. The Partners
 * table keeps the paginated /admin/partners/fetch-partners.
 */
class PartnerOptionController extends Controller
{
    public function __construct(protected PartnerService $partnerService)
    {
    }

    /**
     * Every partner as id, name and company, sorted by name - host only.
     */
    public function index()
    {
        if (!Auth::user()?->partner?->is_host) {
            return ApiResponse::forbidden(__('auth.access_denied_host_only'));
        }

        try {
            return ApiResponse::success(
                PartnerOptionResource::collection($this->partnerService->options()),
                __('partner.success.options_retrieved')
            );
        } catch (\Exception $e) {
            Log::error('Partner options fetch failed', ['error' => $e->getMessage()]);

            return ApiResponse::serverError(__('partner.error.list_failed'));
        }
    }
}
