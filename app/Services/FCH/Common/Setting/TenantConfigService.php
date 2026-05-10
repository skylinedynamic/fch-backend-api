<?php

namespace App\Services\FCH\Common\Setting;

use App\Models\Partner\Partner;

class TenantConfigService
{
    /**
     * Get the tenant config for the authenticated partner by partner_id.
     */
    public function getTenantConfig(int $partnerId): ?Partner
    {
        return Partner::find($partnerId);
    }

    /**
     * Update the tenant config for the authenticated partner.
     */
    public function updateTenantConfig(int $partnerId, array $data): Partner
    {
        $partner = Partner::find($partnerId);

        if (!$partner) {
            throw new \Exception(__('partner.error.not_found'));
        }

        $partner->update([
            'active_custom_url' => $data['active_custom_url'],
            'partner_domain' => $data['partner_domain'] ?? null,
            'borrower_domain' => $data['borrower_domain'] ?? null,
        ]);

        return $partner->fresh();
    }
}
