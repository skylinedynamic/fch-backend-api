<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\MySubscription;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\FCH\Common\MySubscription\MySubscriptionService;
use Illuminate\Http\Request;

class MySubscriptionController extends Controller
{
    public function __construct(protected MySubscriptionService $mySubscriptionService) {}

    /**
     * Get subscription details for authenticated non-host partner.
     */
    public function index()
    {
        try {
            return $this->mySubscriptionService->getSubscriptionDetails();
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('partner.my_subscription_failed') . ': ' . $e->getMessage());
        }
    }

    /**
     * Update default payment method for non-host partner.
     */
    public function updateDefaultPaymentMethod(Request $request)
    {
        try {
            $validated = $request->validate([
                'provider_name' => ['required', 'string'],
                'payment_method_id' => ['nullable', 'string'],
            ]);

            return $this->mySubscriptionService->updateDefaultPaymentMethod($validated);
        } catch (\Exception $e) {
            return ApiResponse::serverError($e->getMessage());
        }
    }
}
