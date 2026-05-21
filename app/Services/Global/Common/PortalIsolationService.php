<?php

namespace App\Services\Global\Common;

use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PortalIsolationService
{
    /**
     * Strip scheme, www, trailing slashes and lowercase — used on BOTH
     * incoming values AND stored DB values so comparisons never fail.
     */
    public function normalizeDomain(string $value): string
    {
        $value = preg_replace('#^https?://#i', '', trim($value)); // strip http:// or https://
        $value = strtolower(explode('/', $value)[0]);             // strip any path after domain
        $value = preg_replace('/^www\./i', '', $value);           // strip www.

        return $value;
    }

    /**
     * Resolve the current domain from the request.
     */
    public function resolveDomain(Request $request): string
    {
        $origin = $request->header('Origin');
        $raw    = ($origin && parse_url($origin, PHP_URL_HOST))
            ? parse_url($origin, PHP_URL_HOST)
            : ($request->header('X-Domain') ?: $request->getHost());

        return $this->normalizeDomain($raw);
    }

    /**
     * Check if a user is allowed to access/login via the current domain.
     */
    public function isUserAllowedOnDomain(User $user, string $domain): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        // Always normalize the incoming domain before ANY comparison
        $domain      = $this->normalizeDomain($domain);
        $userPartner = $user->partner;
        $isUserHost  = $userPartner && $userPartner->is_host;

        // 1. Normalize ALL reserved domain values too
        $reserved           = config('domains.reserved', []);
        $reservedNormalized = array_map(
            fn($v) => $this->normalizeDomain((string) $v),
            $reserved
        );

        $partnerPortal  = $this->normalizeDomain($reserved['base_reserved_partner_portal'] ?? '');
        $borrowerPortal = $this->normalizeDomain($reserved['base_reserved_borrower_portal'] ?? '');

        // 2. Reserved partner portal — host only
        if ($domain === $partnerPortal) {
            return (bool) $isUserHost;
        }

        // 3. Reserved borrower portal — host OR certified partner with no custom borrower domain
        if ($domain === $borrowerPortal) {
            $isCertifiedWithNoDomain = $userPartner
                && $userPartner->partner_type === Partner::PARTNER_TYPE_CERTIFIED
                && empty($userPartner->borrower_domain);

            return $isUserHost || $isCertifiedWithNoDomain;
        }

        // 4. Other reserved domains (API, website) — always allow
        if (in_array($domain, $reservedNormalized, strict: true)) {
            return true;
        }

        // 5. Find partner by domain — normalize stored values at read time
        //    so https://www.awlad.invento.com in DB matches awlad.invento.com incoming
        $partnerDomainOwner = Cache::remember(
            "domain_tenant:{$domain}",
            now()->addMinutes(10),
            function () use ($domain) {
                // Primary: fast indexed lookup (works when DB values are already clean)
                $partner = Partner::where('partner_domain', $domain)
                                  ->orWhere('borrower_domain', $domain)
                                  ->first();

                if ($partner) {
                    return $partner;
                }

                // Fallback: normalize stored values in memory for legacy dirty rows
                // Remove this block once all DB rows are cleaned up
                return Partner::whereNotNull('partner_domain')
                              ->orWhereNotNull('borrower_domain')
                              ->get()
                              ->first(function (Partner $p) use ($domain) {
                                  return $this->normalizeDomain((string) $p->partner_domain) === $domain
                                      || $this->normalizeDomain((string) $p->borrower_domain) === $domain;
                              });
            }
        );

        if ($partnerDomainOwner) {
            return $isUserHost || $user->partner_id == $partnerDomainOwner->id;
        }

        // 6. Unknown domain — host only
        return (bool) $isUserHost;
    }

    /**
     * Get the error message for unauthorized domain access.
     */
    public function getUnauthorizedMessage(User $user, string $domain): string
    {
        return __('auth.unauthorized_portal');
    }
}