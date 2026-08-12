<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Subscription;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\FetchSubscriptionRequest;
use App\Http\Resources\FCH\Subscription\SubscriptionResource;
use App\Services\FCH\Subscription\SubscriptionService;

class SubscriptionController extends Controller
{
    public function __construct(protected SubscriptionService $subscriptionService) {}

    /**
     * Fetch all subscriptions with stats for Subscriptions Module menu (RBAC-scoped).
     */
    public function index(FetchSubscriptionRequest $request)
    {
        return $this->subscriptionService->fetchAll($request->validated());
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

            return ApiResponse::success(new SubscriptionResource($subscription), __('partner.subscription_details'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('partner.subscription_fetch_failed'));
        }
    }
}
