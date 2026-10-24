<?php

namespace App\Traits;

use App\Models\Partner\Partner;
use App\Services\FCH\Partner\PartnerConfigurationChangeRecorder;
use Illuminate\Http\Request;

/**
 * Whose settings a settings request works on.
 *
 * The Email, SMS, Branding and Payment settings endpoints serve the signed-in
 * user's own partner. The host also edits them for another partner, from the
 * partner profile's Configuration tab, by naming it with `partner_id`. Who may
 * do that is decided by the admin panel (`manage partner configuration`); it is
 * not checked here.
 */
trait ResolvesConfigPartner
{
    /**
     * The partner named by `partner_id` (404 when it does not exist), otherwise
     * the caller's own (null when it has none).
     */
    protected function configPartnerId(Request $request): ?int
    {
        if (!$this->namesConfigPartner($request)) {
            $own = $request->user()?->partner_id;

            return $own ? (int) $own : null;
        }

        $partnerId = (int) $request->input('partner_id');
        abort_unless(Partner::whereKey($partnerId)->exists(), 404, __('partner.partner_not_found'));

        return $partnerId;
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
