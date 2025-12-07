<?php

namespace App\Services\FCH\Partner;

use App\Models\Partner\PartnerSubscription;
use Illuminate\Support\Facades\Log;

class PartnerSubscriptionService
{
    /**
     * Create or Update Subscription
     *
     * @param int $partnerId
     * @param array $data
     * @return PartnerSubscription
     */
    public function createOrUpdate(int $partnerId, array $data)
    {
        // Handle installment_type logic
        $installmentType = $data['installment_type'] ?? 0;
        if ($installmentType != 1) {
            $data['first_reg_payment_2'] = null;
        }

        // Handle enrollment_type logic
        if (isset($data['enrollment_type']) && $data['enrollment_type'] === 'One-Time Payment') {
            $data['monthly_platform_fee'] = null;
        }

        return PartnerSubscription::updateOrCreate(
            ['partner_id' => $partnerId],
            $data
        );
    }

    /**
     * Get Subscription by Subscription ID
     *
     * @param int $subscriptionId
     * @return PartnerSubscription|null
     */
    public function getByPartnerId(int $partnerId)
    {
        return PartnerSubscription::where('partner_id', $partnerId)->first();
    }

    /**
     * Get All Subscriptions
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAll(int $partnerId)
    {
        return PartnerSubscription::where('partner_id', $partnerId)->with('partner')->get();
    }
}
