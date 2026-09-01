<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Billing;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Billing\FetchUpcomingBillingRequest;
use App\Services\FCH\Common\Billing\BillingService;

class BillingController extends Controller
{
    public function __construct(protected BillingService $billingService) {}

    /**
     * Fetch upcoming billings.
     */
    public function fetchUpcoming(FetchUpcomingBillingRequest $request)
    {
        try {
            return $this->billingService->fetchUpcoming($request->validated());
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('billing.failed_upcoming') . ': ' . $e->getMessage());
        }
    }

    /**
     * Get billing details for a subscriber.
     */
    public function details(int $id)
    {
        try {
            $data = $this->billingService->getDetails($id);
            if (!$data) {
                return ApiResponse::notFound(__('billing.subscriber_not_found'));
            }

            return ApiResponse::success($data, __('billing.details_retrieved'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('billing.failed_details') . ': ' . $e->getMessage());
        }
    }
}
