<?php

namespace App\Traits;

use Illuminate\Http\Request;

/**
 * Whose agreements an agreement request works on: the caller's own partner, or
 * - from Partner profile › Configuration - another partner named with
 * `partner_id`. A partner's agreement is what its borrowers sign, so unlike the
 * other settings endpoints naming another partner is checked: only a host user
 * holding the on-behalf permission may.
 */
trait ResolvesAgreementOwner
{
    use ResolvesConfigPartner;

    /**
     * Resolve outside an action's try block, so a 403/404 is not turned into a server error.
     * Who may name another partner: mayManageOtherPartners() - a host user holding
     * Permission::ManagePartnerConfiguration.
     */
    protected function agreementOwnerId(Request $request): int
    {
        if ($this->namesConfigPartner($request)) {
            abort_unless($this->mayManageOtherPartners($request), 403, __('partner.configuration_forbidden'));
        }

        return (int) $this->configPartnerId($request);
    }
}
