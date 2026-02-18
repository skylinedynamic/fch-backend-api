<?php

namespace App\Http\Controllers\Api\V1\GlobalPayment;

use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\GlobalPayment\GlobalPaymentService;
use App\Http\Requests\Api\V1\GlobalPayment\GlobalPaymentRequest;
use App\Http\Requests\Api\V1\GlobalPayment\GlobalPaymentForUserRequest;

class GlobalPaymentController extends Controller
{
    public function __construct(protected GlobalPaymentService $paymentService) {}

    /**
     * Process a payment.
     */
    public function processPayment(GlobalPaymentRequest $request): JsonResponse
    {
        try {
            $paymentIntent = $this->paymentService->processPayment($request->validated());

            return ApiResponse::success($paymentIntent, __('payment.processed'));
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }

    /**
     * Process a payment for users (Seat Purchase).
     */
    public function processPaymentForUsers(GlobalPaymentForUserRequest $request): JsonResponse
    {
        try {
            $payment = $this->paymentService->processPaymentForUsers($request->validated());

            return ApiResponse::success($payment, __('payment.processed'));
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }
}
