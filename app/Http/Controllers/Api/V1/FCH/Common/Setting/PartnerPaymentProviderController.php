<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Setting;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Traits\ResolvesConfigPartner;
use App\Services\FCH\Partner\PartnerConfigurationChangeRecorder;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
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
    use ResolvesConfigPartner;

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
        $partnerId = $this->configPartnerId($request);

        $providers = $this->configService->getProviders($partnerId);

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
        $partnerId = $this->configPartnerId(request());
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
        $partnerId = $this->configPartnerId(request());
        if (!$partnerId) {
            return ApiResponse::validationError(['partner_id' => [__('payment_method.partner_id_required')]], __('payment_method.partner_id_required'));
        }

        $providerId = (int) $request->validated('payment_provider_id');
        $before = $this->snapshot($this->linkage($partnerId, $providerId));

        $linkage = $this->configService->updateOrCreateConfig($partnerId, $request->validated());

        $after = $this->snapshot($linkage->fresh());
        $this->recordConfigChange(
            $request,
            PartnerConfigurationChangeRecorder::AREA_PAYMENT,
            "updated the {$this->providerName($providerId)} payment settings",
            PartnerConfigurationChangeRecorder::diff(
                $before,
                $after,
                ['payment_mode' => 'Mode', 'status' => 'Active', 'is_default' => 'Default'],
                // Credentials and bank details: reported as changed, never shown.
                array_values(array_diff(array_keys($before + $after), ['payment_mode', 'status', 'is_default']))
            )
        );

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
        $partnerId = $this->configPartnerId(request());
        if (!$partnerId) {
            return ApiResponse::validationError(['partner_id' => [__('payment_method.partner_id_required')]], __('payment_method.partner_id_required'));
        }

        $linkage = $this->configService->updateStatus($partnerId, $request->validated());

        $name = $this->providerName((int) $request->validated('payment_provider_id'));
        $this->recordConfigChange(
            $request,
            PartnerConfigurationChangeRecorder::AREA_PAYMENT,
            $request->boolean('status') ? "turned {$name} on" : "turned {$name} off"
        );

        return ApiResponse::success(
            new PartnerPaymentProviderResource($linkage),
            __('payment_method.saved')
        );
    }

    private function linkage(int $partnerId, int $providerId): ?PartnerPaymentProvider
    {
        return PartnerPaymentProvider::where('partner_id', $partnerId)->where('payment_provider_id', $providerId)->first();
    }

    private function providerName(int $providerId): string
    {
        $provider = PaymentProvider::find($providerId);

        return $provider?->display_name ?: ($provider?->name ?: 'the payment provider');
    }

    /** The linkage's fields, for the activity log's before/after comparison. */
    private function snapshot(?PartnerPaymentProvider $linkage): array
    {
        if (!$linkage) {
            return [];
        }

        return [
            'payment_mode' => $linkage->payment_mode,
            'status' => (bool) $linkage->status,
            'is_default' => (bool) $linkage->is_default,
        ] + ($linkage->configuration ?? []);
    }
}
