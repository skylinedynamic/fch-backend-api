<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Setting;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Http\Resources\FCH\PaymentMethod\PartnerPaymentProviderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PartnerPaymentProviderController extends Controller
{
    /**
     * Get list of active payment providers for the current partner.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $partnerId = Auth::user()->partner_id;
        if (!$partnerId) {
            return ApiResponse::validationError(['partner_id' => [__('payment_method.partner_id_required')]], __('payment_method.partner_id_required'));
        }

        // Fetch system-wide active providers
        $providers = PaymentProvider::where('status', true)->get();

        // Fetch partner linkages
        $partnerProviders = PartnerPaymentProvider::where('partner_id', $partnerId)
            ->get()
            ->keyBy('payment_provider_id');

        $data = $providers->map(function ($provider) use ($partnerProviders) {
            $linkage = $partnerProviders->get($provider->id);

            return [
                'id' => $provider->id,
                'name' => $provider->name,
                'slug' => $provider->slug ?? strtolower(str_replace([' ', '.'], '', $provider->name)),
                'logo' => $provider->logo,
                'status' => $linkage ? (bool) $linkage->status : false,
                'is_default' => $linkage ? (bool) $linkage->is_default : false,
                'is_configured' => $linkage ? (!empty($linkage->configuration)) : false,
            ];
        });

        return ApiResponse::success($data, __('payment_method.retrieved'));
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

        $provider = PaymentProvider::findOrFail($id);

        $linkage = PartnerPaymentProvider::where('partner_id', $partnerId)
            ->where('payment_provider_id', $id)
            ->first();

        $resourceData = $linkage ?: (object)[
            'id' => null,
            'partner_id' => $partnerId,
            'payment_provider_id' => $provider->id,
            'status' => false,
            'is_default' => false,
            'configuration' => new \stdClass()
        ];

        return ApiResponse::success(
            new PartnerPaymentProviderResource($resourceData),
            __('payment_method.retrieved')
        );
    }

    /**
     * Store or update provider configuration.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $partnerId = Auth::user()->partner_id;
        if (!$partnerId) {
            return ApiResponse::validationError(['partner_id' => [__('payment_method.partner_id_required')]], __('payment_method.partner_id_required'));
        }

        $request->validate([
            'payment_provider_id' => 'required|exists:payment_providers,id',
            'status' => 'boolean',
            'is_default' => 'boolean',
            'configuration' => 'nullable|array',
        ]);

        $providerId = $request->input('payment_provider_id');
        $status = $request->input('status', true);
        $isDefault = $request->input('is_default', false);

        // Find existing record to preserve configuration if not provided
        $existingLinkage = PartnerPaymentProvider::where('partner_id', $partnerId)
            ->where('payment_provider_id', $providerId)
            ->first();

        $configuration = $request->has('configuration')
            ? $request->input('configuration')
            : ($existingLinkage ? $existingLinkage->configuration : []);

        // If setting as default, clear other default configurations for this partner
        if ($isDefault) {
            PartnerPaymentProvider::where('partner_id', $partnerId)->update(['is_default' => false]);
        }

        $linkage = PartnerPaymentProvider::updateOrCreate(
            [
                'partner_id' => $partnerId,
                'payment_provider_id' => $providerId,
            ],
            [
                'status' => $status,
                'is_default' => $isDefault,
                'configuration' => $configuration,
            ]
        );

        return ApiResponse::success(
            new PartnerPaymentProviderResource($linkage),
            __('payment_method.saved')
        );
    }
}
