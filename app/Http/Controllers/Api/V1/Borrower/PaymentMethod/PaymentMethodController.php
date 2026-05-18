<?php

namespace App\Http\Controllers\Api\V1\Borrower\PaymentMethod;

use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\Borrower\PaymentMethod\PaymentMethodService;
use App\Http\Resources\FCH\Common\PaymentMethod\PaymentMethodResource;

class PaymentMethodController extends Controller
{
    protected $paymentMethodService;

    public function __construct(PaymentMethodService $paymentMethodService)
    {
        $this->paymentMethodService = $paymentMethodService;
    }

    /**
     * Get Settings details for the borrower.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        $setting = $this->paymentMethodService->getSettings();

        if (!$setting) {
            return ApiResponse::notFound(__('payment_method.not_found'));
        }

        return ApiResponse::success(new PaymentMethodResource($setting), __('payment_method.retrieved'));
    }
}
