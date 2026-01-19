<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Payment;

use App\Http\Controllers\Controller;
use App\Services\FCH\Common\Payment\PaymentService;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(protected PaymentService $paymentService) {}

    /**
     * List payments.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->paymentService->list($request->all());
    }

    /**
     * Get payment details.
     */
    public function show($id): JsonResponse
    {
        try {
            $payment = $this->paymentService->show($id);
            return ApiResponse::success($payment, __('payment.details_retrieved'));
        } catch (\Exception $e) {
            return ApiResponse::notFound(__('payment.not_found'));
        }
    }

    /**
     * Get payment statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $stats = $this->paymentService->getStats($request->all());
        return ApiResponse::success($stats, __('payment.stats_retrieved'));
    }
}
