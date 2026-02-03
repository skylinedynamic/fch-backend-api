<?php

namespace App\Http\Controllers\Api\V1\Borrower\Payment;

use App\Http\Controllers\Controller;
use App\Services\Borrower\Payment\PaymentService;
use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\Api\V1\Borrower\Payment\FetchPayment;

class PaymentController extends Controller
{
    public function __construct(protected PaymentService $paymentService) {}

    /**
     * List payments for borrower.
     */
    public function index(FetchPayment $request): JsonResponse
    {
        return $this->paymentService->list($request->validated());
    }

    /**
     * Get payment details for borrower.
     */
    public function show($id): JsonResponse
    {
        $payment = $this->paymentService->find($id);

        if (!$payment) {
            return ApiResponse::notFound(__('payment.not_found'));
        }

        return ApiResponse::success($payment, __('payment.details_retrieved'));
    }

    /**
     * Get payment statistics for borrower.
     */
    public function stats(FetchPayment $request): JsonResponse
    {
        $stats = $this->paymentService->getStats($request->validated());
        return ApiResponse::success($stats, __('payment.stats_retrieved'));
    }
}
