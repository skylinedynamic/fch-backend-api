<?php

namespace App\Http\Controllers\Api\V1\GlobalPayment;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GlobalPayment\ProcessPaymentRequest;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use App\Services\FCH\Common\PaymentMethod\PaymentMethodService;
use App\Services\GlobalPayment\UnifiedPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Support\Facades\Log;

class UnifiedPaymentController extends Controller
{
    public function __construct(
        protected UnifiedPaymentService $paymentService,
        protected PaymentMethodService $paymentMethodService,
    ) {
    }

    /**
     * Process dynamic payment via unified driver architecture.
     *
     * @param ProcessPaymentRequest $request
     * @return JsonResponse
     */
    public function processPayment(ProcessPaymentRequest $request): JsonResponse
    {

        try {
            $result = $this->paymentService->processPayment($request->validated());

            return ApiResponse::success($result, __('payment.processed'));
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }



    /**
     * List active payment providers configured for a partner.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getProviders(Request $request): JsonResponse
    {
        try {
            $partnerId = $request->query('partner_id', auth()->user()?->partner_id);

            $providers = PaymentProvider::query()
                ->where('status', true)
                ->whereHas('partnerPaymentProviders', function ($q) use ($partnerId) {
                    if ($partnerId) {
                        $q->where('partner_id', $partnerId)->where('status', true);
                    }
                })
                ->get();

            return ApiResponse::success($providers, __('payment_method.retrieved'));
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }

    /**
     * Get active partner payment methods for the authenticated user's partner.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getPartnerPaymentMethods(Request $request): JsonResponse
    {
        try {
            $providers = $this->paymentMethodService->getActiveProvidersForPartner();
            return ApiResponse::success($providers, __('payment_method.retrieved'));
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }

    /**
     * Resolve the Stripe Secret Key based on the invoice receiver entity.
     *
     * @param Invoice $invoice
     * @return string
     * @throws Exception
     */
    protected function resolveStripeSecretKey(Invoice $invoice): string
    {
        $receiverPartnerId = null;
        if ($invoice->is_partner_paying || $invoice->entity_type === Partner::class) {
            $receiverPartnerId = Partner::where('is_host', true)->first()?->id;
        } else {
            $receiverPartnerId = $invoice->partner_id;
        }

        if (!$receiverPartnerId) {
            throw new Exception("Receiver partner ID could not be resolved.");
        }

        $stripeProvider = PaymentProvider::where('name', PaymentProvider::STRIPE)->first();
        if (!$stripeProvider) {
            throw new Exception("Stripe payment provider not found in system.");
        }

        $partnerProvider = PartnerPaymentProvider::query()
            ->where('partner_id', $receiverPartnerId)
            ->where('payment_provider_id', $stripeProvider->id)
            ->where('status', true)
            ->first();

        if (!$partnerProvider) {
            throw new Exception("Stripe provider is not configured or active for this partner.");
        }

        $config = $partnerProvider->configuration ?? [];
        $environment = $partnerProvider->payment_mode ?? ($config['payment_mode'] ?? PaymentProvider::MODE_SANDBOX);
        $envConfig = $config[$environment] ?? $config;

        $secretKey = $envConfig['secret_key'] ?? ($config['stripe_secret_' . $environment] ?? null);

        if (!$secretKey) {
            throw new Exception("Stripe secret key not configured for " . $environment . " mode.");
        }

        return $secretKey;
    }
}
