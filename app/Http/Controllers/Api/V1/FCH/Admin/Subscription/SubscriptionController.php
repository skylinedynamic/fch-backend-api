<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Subscription;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\StoreUpdateSubscriptionRequest;
use App\Http\Resources\FCH\Subscription\SubscriptionResource;
use App\Services\FCH\Subscription\SubscriptionService;

class SubscriptionController extends Controller
{
    public function __construct(protected SubscriptionService $subscriptionService) {}

    /**
     * Create And Update Subscription for Partner.
     */
    public function store(StoreUpdateSubscriptionRequest $request)
    {
        try {
            $data = $request->validated();
            $partnerId = $data['partner_id'] ?? null;
            $borrowerId = $data['borrower_id'] ?? null;

            $searchFields = [];
            if ($partnerId) $searchFields['partner_id'] = $partnerId;
            if ($borrowerId) $searchFields['borrower_id'] = $borrowerId;

            if (empty($searchFields)) {
                return ApiResponse::error('Entity ID required');
            }

            $subscription = $this->subscriptionService->createOrUpdate($searchFields, $data);

            return ApiResponse::success(new SubscriptionResource($subscription), __('partner.subscription_saved'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('partner.subscription_save_failed'));
        }
    }
}
