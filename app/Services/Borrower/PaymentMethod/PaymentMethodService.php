<?php

namespace App\Services\Borrower\PaymentMethod;

use Illuminate\Support\Facades\Auth;

class PaymentMethodService
{
    /**
     * Get Settings for the authenticated borrower's partner.
     *
     * @return Setting|null
     */
    public function getSettings(): ?object
    {
        $partnerId = Auth::user()->partner_id ?? null;
        $service = app(\App\Services\FCH\Common\PaymentMethod\PaymentMethodService::class);
        return $service->getSettings($partnerId);
    }
}
