<?php

namespace App\Traits;

use App\Enums\Permission;
use App\Models\Partner\Partner;
use App\Services\FCH\Partner\PartnerConfigurationChangeRecorder;
use Illuminate\Http\Request;

/**
 * Whose settings a settings request works on.
 *
 * The Email, SMS, Branding and Payment settings endpoints serve the signed-in
 * user's own partner. The host also edits them for another partner, from the
 * partner profile's Configuration tab, by naming it with `partner_id`.
 *
 * Only a host user holding Permission::ManagePartnerConfiguration may name
 * another partner; anyone else gets 403 (R3-BUG-001 - hiding the tab in the
 * admin panel was the only check, so a partner could read and change another
 * partner's gateways, mailboxes and keys by adding `partner_id`).
 */
trait ResolvesConfigPartner
{
    /**
     * The partner named by `partner_id`, otherwise the caller's own (null when
     * it has none). Naming another partner without the right is 403 - checked
     * before the 404 for an unknown partner, so the answer does not tell a
     * partner which ids exist.
     */
    protected function configPartnerId(Request $request): ?int
    {
        $own = $request->user()?->partner_id;

        if (!$this->namesConfigPartner($request)) {
            return $own ? (int) $own : null;
        }

        $partnerId = (int) $request->input('partner_id');

        if ($partnerId !== (int) $own) {
            abort_unless($this->mayManageOtherPartners($request), 403, __('partner.configuration_forbidden'));
        }

        abort_unless(Partner::whereKey($partnerId)->exists(), 404, __('partner.partner_not_found'));

        return $partnerId;
    }

    /** A host user holding the on-behalf permission. */
    protected function mayManageOtherPartners(Request $request): bool
    {
        $user = $request->user();

        return (bool) ($user?->partner?->is_host && $user->can(Permission::ManagePartnerConfiguration->value));
    }

    /** Whether the request names another partner at all. */
    protected function namesConfigPartner(Request $request): bool
    {
        return $request->filled('partner_id');
    }

    /**
     * After a successful change: when it was made for another partner, log it
     * on that partner and tell it (PartnerConfigurationChangeRecorder::AREA_*).
     *
     * @param  string  $summary  What was done, e.g. "updated the SMS configuration".
     * @param  array<string, array<string, ?string>>  $changes  PartnerConfigurationChangeRecorder::diff() map.
     */
    protected function recordConfigChange(Request $request, string $area, string $summary, array $changes = []): void
    {
        if (!$this->namesConfigPartner($request)) {
            return;
        }

        $partnerId = (int) $request->input('partner_id');
        if ($partnerId === (int) $request->user()?->partner_id) {
            return;
        }

        app(PartnerConfigurationChangeRecorder::class)->record($partnerId, $area, $summary, $changes);
    }
}
