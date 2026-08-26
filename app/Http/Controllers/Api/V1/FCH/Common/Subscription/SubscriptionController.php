<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Subscription;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\FetchSubscriptionRequest;
use App\Http\Resources\FCH\Subscription\SubscriptionDetailsResource;
use App\Services\FCH\Subscription\SubscriptionService;

class SubscriptionController extends Controller
{
    public function __construct(protected SubscriptionService $subscriptionService)
    {
    }

    /**
     * Fetch all subscriptions with stats for Subscriptions Module menu (RBAC-scoped).
     */
    public function index(FetchSubscriptionRequest $request)
    {
        return $this->subscriptionService->fetchAll($request->validated());
    }

    /**
     * Get borrower fee for authenticated partner from their subscription,
     * or fallback to fixed enrollment plan from subscription_plans table.
     */
    public function getPartnerBorrowerFee()
    {
        return $this->subscriptionService->getPartnerBorrowerFee();
    }

    /**
     * Get single subscription details by ID (RBAC-scoped).
     */
    public function show(int $id)
    {
        try {
            $subscription = $this->subscriptionService->getById($id);

            if (!$subscription) {
                return ApiResponse::error(__('partner.subscription_not_found'), 404);
            }

            return ApiResponse::success(new SubscriptionDetailsResource($subscription), __('partner.subscription_details'));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Subscription show failed', ['id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return ApiResponse::serverError(__('partner.subscription_fetch_failed'));
        }
    }
}
