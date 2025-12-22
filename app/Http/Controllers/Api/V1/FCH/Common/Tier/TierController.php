<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Tier;

use App\Http\Controllers\Controller;
use App\Services\FCH\Common\Tier\TierService;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;

class TierController extends Controller
{
    protected TierService $tierService;

    public function __construct(TierService $tierService)
    {
        $this->tierService = $tierService;
    }

    /**
     * List all tiers.
     */
    public function index()
    {
        try {
            $tiers = $this->tierService->list();
            return ApiResponse::success($tiers, __('tier.success_list'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('tier.error_list_failed'));
        }
    }
}
