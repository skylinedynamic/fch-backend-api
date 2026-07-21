<?php

namespace App\Services\GlobalPayment;

use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\User\User;
use App\Services\FCH\Common\PaymentMethod\PaymentMethodService;
use Illuminate\Support\Facades\Auth;
use Stripe\Stripe;

class PaymentService
{
    /**
     * Resolve the target partner ID dynamically from inputs and context.
     *
     * @param array $data
     * @return int|null
     */
    public function resolvePartnerId(array $data): ?int
    {
        $partnerId = null;

        if (!empty($data['invoice_id'])) {
            $invoice = Invoice::where('id', $data['invoice_id'])->first();
            if ($invoice) {
                // If it is a partner invoice (partner paying the host platform fee/enrollment fee),
                // configure Stripe for the Host because the host receives the payment.
                if ($invoice->is_partner_paying || $invoice->entity_type === Partner::class) {
                    $partnerId = Partner::where('is_host', true)->first()?->id;
                } else {
                    $partnerId = $invoice->partner_id;
                }
            }
        }

        if (!$partnerId && Auth::check()) {
            if (in_array(Auth::user()->user_type, [User::PARTNER, User::ADMIN])) {
                $partnerId = Partner::where('is_host', true)->first()?->id;
            } else {
                $partnerId = Auth::user()->partner_id;
            }
        }

        if (!$partnerId) {
            $partnerId = Partner::where('is_host', true)->first()?->id;
        }

        return $partnerId;
    }

    public function configureStripeForPartner(?int $partnerId): object
    {
        $paymentMethodService = new PaymentMethodService();
        $setting = $paymentMethodService->getSettings($partnerId);

        if (!$setting) {
            throw new \Exception('Payment settings not found or inactive.');
        }

        $secretKey = ($setting->payment_mode === Payment::MODE_PRODUCTION)
            ? $setting->stripe_secret_production
            : $setting->stripe_secret_sandbox;

        if (!$secretKey) {
            throw new \Exception('Stripe secret key not configured for ' . $setting->payment_mode . ' mode.');
        }

        Stripe::setApiKey($secretKey);

        return $setting;
    }
}
