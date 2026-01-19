<?php

namespace App\Http\Controllers\Api\V1\Global\Payment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Global\Payment\PaymentRequest;
use App\Services\Global\Payment\PaymentService;
use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(protected PaymentService $paymentService) {}

    /**
     * Process a payment.
     */
    public function processPayment(PaymentRequest $request): JsonResponse
    {
        try {
            $paymentIntent = $this->paymentService->processPayment($request->validated());

            return ApiResponse::success($paymentIntent, __('payment.processed'));
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }
}
