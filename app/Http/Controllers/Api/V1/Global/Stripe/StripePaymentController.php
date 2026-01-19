<?php

namespace App\Http\Controllers\Api\V1\Global\Stripe;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Global\Stripe\StripePaymentRequest;
use App\Services\Global\Stripe\StripeService;
use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;

class StripePaymentController extends Controller
{
    public function __construct(protected StripeService $stripeService) {}

    /**
     * Process a Stripe payment.
     */
    public function processPayment(StripePaymentRequest $request): JsonResponse
    {
        try {
            $paymentIntent = $this->stripeService->processPayment($request->validated());

            return ApiResponse::success($paymentIntent, 'Payment processed successfully.');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }
}
