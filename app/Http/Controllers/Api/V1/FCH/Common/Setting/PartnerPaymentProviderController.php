<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Setting;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Setting\StorePartnerPaymentProviderRequest;
use App\Http\Requests\Api\V1\FCH\Common\Setting\UpdatePartnerPaymentProviderStatusRequest;
use App\Http\Resources\FCH\PaymentMethod\PartnerPaymentProviderResource;
use App\Http\Resources\FCH\PaymentMethod\PartnerPaymentProviderListResource;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PartnerPaymentProviderController extends Controller
{
    protected $configService;

    public function __construct(PaymentMethodConfigurationService $configService)
    {
        $this->configService = $configService;
    }

    /**
     * Get list of active payment providers for the current partner.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $partnerId = $user->partner_id;

        if (!$partnerId && $user && $user->entity_type === Borrower::class) {
            $borrower = Borrower::find($user->entity_id);
            $partnerId = $borrower?->partner_id;
        }

        if (!$partnerId) {
            $partnerId = Partner::host()?->id;
        }

        if (!$partnerId) {
            return ApiResponse::validationError(['partner_id' => [__('payment_method.partner_id_required')]], __('payment_method.partner_id_required'));
        }

        $activeOnly = $request->has('active_only') ? $request->boolean('active_only') : true;
        $providers = $this->configService->getProviders($partnerId, $activeOnly);

        return ApiResponse::success(
            PartnerPaymentProviderListResource::collection($providers),
            __('payment_method.retrieved')
        );
    }

    /**
     * Get configuration for a specific payment provider.
     *
     * @param mixed $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $partnerId = Auth::user()->partner_id;
        if (!$partnerId) {
            return ApiResponse::validationError(['partner_id' => [__('payment_method.partner_id_required')]], __('payment_method.partner_id_required'));
        }

        $resourceData = $this->configService->getProviderConfig($partnerId, $id);

        return ApiResponse::success(
            new PartnerPaymentProviderResource($resourceData),
            __('payment_method.retrieved')
        );
    }

    /**
     * Store or update provider configuration.
     *
     * @param StorePartnerPaymentProviderRequest $request
     * @return JsonResponse
     */
    public function store(StorePartnerPaymentProviderRequest $request): JsonResponse
    {
        $partnerId = Auth::user()->partner_id;
        if (!$partnerId) {
            return ApiResponse::validationError(['partner_id' => [__('payment_method.partner_id_required')]], __('payment_method.partner_id_required'));
        }

        $linkage = $this->configService->updateOrCreateConfig($partnerId, $request->validated());

        return ApiResponse::success(
            new PartnerPaymentProviderResource($linkage),
            __('payment_method.saved')
        );
    }

    /**
     * Update active status of a provider.
     *
     * @param UpdatePartnerPaymentProviderStatusRequest $request
     * @return JsonResponse
     */
    public function updateStatus(UpdatePartnerPaymentProviderStatusRequest $request): JsonResponse
    {
        $partnerId = Auth::user()->partner_id;
        if (!$partnerId) {
            return ApiResponse::validationError(['partner_id' => [__('payment_method.partner_id_required')]], __('payment_method.partner_id_required'));
        }

        $linkage = $this->configService->updateStatus($partnerId, $request->validated());

        return ApiResponse::success(
            new PartnerPaymentProviderResource($linkage),
            __('payment_method.saved')
        );
    }
}
