<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Payment;

use App\Http\Controllers\Controller;
use App\Services\FCH\Common\Payment\PaymentService;
use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\Api\V1\FCH\Common\Payment\FetchPayment;
use App\Http\Requests\Api\V1\FCH\Common\Payment\ApprovePayment;

class PaymentController extends Controller
{
    public function __construct(protected PaymentService $paymentService) {}

    /**
     * List payments.
     */
    public function index(FetchPayment $request): JsonResponse
    {
        return $this->paymentService->list($request->validated());
    }

    /**
     * Get payment details.
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
     * Get payment statistics.
     */
    public function stats(FetchPayment $request): JsonResponse
    {
        $stats = $this->paymentService->getStats($request->validated());
        return ApiResponse::success($stats, __('payment.stats_retrieved'));
    }

    /**
     * Approve payment.
     */
    public function approve(ApprovePayment $request, $id): JsonResponse
    {
        $result = $this->paymentService->approvePayment($id, $request->validated());

        if (!$result['status']) {
            return ApiResponse::error($result['message'], 422);
        }

        return ApiResponse::success($result['data'], $result['message']);
    }
}
