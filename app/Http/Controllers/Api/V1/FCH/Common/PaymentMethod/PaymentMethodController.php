<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\PaymentMethod;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\PaymentMethod\StorePaymentMethodRequest;
use App\Http\Resources\FCH\Common\PaymentMethod\PaymentMethodResource;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\PaymentMethod\PaymentMethodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentMethodController extends Controller
{
    protected $paymentMethodService;

    public function __construct(PaymentMethodService $paymentMethodService)
    {
        $this->paymentMethodService = $paymentMethodService;
    }

    /**
     * Store or Update Settings.
     *
     * @param  StoreSettingRequest  $request
     */
    public function store(StorePaymentMethodRequest $request): JsonResponse
    {

        $setting = $this->paymentMethodService->updateOrCreate($request->validated());

        return ApiResponse::success(new PaymentMethodResource($setting), __('payment_method.saved'));
    }

    /**
     * Get Settings details.
     */
    public function show(Request $request): JsonResponse
    {
        $partnerId = null;

        $partner = getConfigPartner();

        $partnerId = $partner->id;

        if (! $partnerId) {
            return ApiResponse::validationError(['partner_id' => [__('payment_method.partner_id_required')]], __('payment_method.partner_id_required'));
        }

        $setting = $this->paymentMethodService->getSettings($partnerId);

        if (! $setting) {
            return ApiResponse::notFound(__('payment_method.not_found'));
        }

        return ApiResponse::success(new PaymentMethodResource($setting), __('payment_method.retrieved'));
    }
}
