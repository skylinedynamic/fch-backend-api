<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Partner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\StoreUpdatePartnerSubscriptionRequest;
use App\Services\FCH\Partner\PartnerSubscriptionService;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;

use App\Http\Resources\FCH\Partner\PartnerSubscriptionResource;

class PartnerSubscriptionController extends Controller
{
    public function __construct(protected PartnerSubscriptionService $subscriptionService)
    {
    }

    /**
     * Create And Update Subscription
     */
    public function store(StoreUpdatePartnerSubscriptionRequest $request)
    {
        try {
            $data = $request->validated();
            
            $subscription = $this->subscriptionService->createOrUpdate($data['partner_id'], $data);

            return ApiResponse::success(new PartnerSubscriptionResource($subscription), __('partner.subscription_saved'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('partner.subscription_save_failed'));
        }
    }
    
    /**
     * Get Subscriptions
     */
    public function show(int $partnerId)
    {
        try {
             $subscription = $this->subscriptionService->getByPartnerId($partnerId);
             return ApiResponse::success(new PartnerSubscriptionResource($subscription), __('partner.subscription_details'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('partner.subscription_fetch_failed'));
        }
    }
}
