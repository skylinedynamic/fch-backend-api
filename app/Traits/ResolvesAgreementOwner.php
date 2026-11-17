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

    /** Who may name another partner's agreements: a host user holding it. */
    protected const ON_BEHALF_PERMISSION = 'manage partner configuration';

    /** Resolve outside an action's try block, so a 403/404 is not turned into a server error. */
    protected function agreementOwnerId(Request $request): int
    {
        if ($this->namesConfigPartner($request)) {
            $user = $request->user();
            abort_unless(
                $user?->partner?->is_host && $user->can(self::ON_BEHALF_PERMISSION),
                403,
                __('partner.configuration_forbidden')
            );
        }

        return (int) $this->configPartnerId($request);
    }
}
