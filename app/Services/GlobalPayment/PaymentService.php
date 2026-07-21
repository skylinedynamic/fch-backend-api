<?php

namespace App\Services\GlobalPayment;

use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PaymentMethod;
use App\Models\User\User;
use RuntimeException;
use Stripe\Stripe;

class PaymentService
{
    public function resolvePartnerId(array $data): ?int
    {
        if (!empty($data['invoice_id'])) {
            $invoice = Invoice::where('id', $data['invoice_id'])->first();

            if ($invoice) {
                if ($invoice->is_partner_paying || $invoice->entity_type === Partner::class) {
                    return Partner::host()?->id;
                }

                return $invoice->partner_id;
            }
        }

        if (auth()->check()) {
            if (in_array(auth()->user()->user_type, [User::PARTNER, User::ADMIN], true)) {
                return Partner::host()?->id;
            }

            return auth()->user()->partner_id;
        }

        return Partner::host()?->id;
    }

    public function configurePaymentMethod(?int $partnerId): object
    {
        $query = PaymentMethod::query();

        if ($partnerId) {
            $query->where('partner_id', $partnerId);
        } else {
            $query->whereNull('partner_id');
        }

        $methods = $query->get();

        $flat = new \stdClass();
        $flat->id = 1;
        $flat->partner_id = $partnerId;
        $flat->payment_mode = PaymentMethod::MODE_SANDBOX;
        $flat->stripe_key_sandbox = null;
        $flat->stripe_secret_sandbox = null;
        $flat->stripe_key_production = null;
        $flat->stripe_secret_production = null;
        $flat->payment_method = PaymentMethod::STRIPE;
        $flat->status = true;
        $flat->configuration = null;
        $flat->partner_auto_charge_host_fee = 1;

        foreach ($methods as $method) {
            $flat->{$method->key} = $method->value;
        }

        $flat->partner_auto_charge_host_fee = (bool) filter_var($flat->partner_auto_charge_host_fee, FILTER_VALIDATE_BOOLEAN);

        $secretKey = $flat->payment_mode === PaymentMethod::MODE_PRODUCTION
            ? $flat->stripe_secret_production
            : $flat->stripe_secret_sandbox;

        if (!$secretKey) {
            throw new RuntimeException('Stripe secret key not configured for ' . $flat->payment_mode . ' mode.');
        }

        Stripe::setApiKey($secretKey);

        return $flat;
    }
}
