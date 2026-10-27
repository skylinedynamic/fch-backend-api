<?php

namespace App\Services\Global\Common;

use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PortalIsolationService
{
    /**
     * Strip scheme, www, trailing slashes and lowercase — used on BOTH
     * incoming values AND stored DB values so comparisons never fail.
     */
    public function normalizeDomain(string $value): string
    {
        $value = preg_replace('#^https?://#i', '', trim($value));
        $value = strtolower(explode('/', $value)[0]);
        $value = preg_replace('/^www\./i', '', $value);

        return $value;
    }

    /**
     * Resolve the current domain from the request.
     */
    public function resolveDomain(Request $request): string
    {
        $origin    = $request->header('Origin');
        $xDomain   = $request->header('X-Domain');
        $host      = $request->getHost();

        $raw = ($origin && parse_url($origin, PHP_URL_HOST))
            ? parse_url($origin, PHP_URL_HOST)
            : ($xDomain ?: $host);

        $resolved = $this->normalizeDomain($raw);

        return $resolved;
    }

    /**
     * Check if a user is allowed to access/login via the current domain.
     */
    public function isUserAllowedOnDomain(User $user, string $domain): bool
    {
        // --- Local env bypass ---
        if (app()->environment('local')) {
            return true;
        }

        $domain      = $this->normalizeDomain($domain);
        $userPartner = $user->partner;
        $isUserHost  = $userPartner && $userPartner->is_host;


        // --- Reserved domains ---
        $reserved           = config('domains.reserved', []);
        $reservedNormalized = array_map(
            fn($v) => $this->normalizeDomain((string) $v),
            $reserved
        );

        $partnerPortal  = $this->normalizeDomain($reserved['base_reserved_partner_portal'] ?? '');
        $borrowerPortal = $this->normalizeDomain($reserved['base_reserved_borrower_portal'] ?? '');


        // --- Check 1: Reserved partner portal only can access the host partner ---
        if ($domain == $partnerPortal && $isUserHost) {
            return true;
        }

        // --- Check 2: Reserved borrower portal only can access the host partner borrower or certified partner borrower with no domain ---
        if ($domain == $borrowerPortal) {

            if($isUserHost){
                return true;
            }
            
            $isCertifiedWithNoDomain = $userPartner
                && $userPartner->partner_type === Partner::PARTNER_TYPE_CERTIFIED
                && empty($userPartner->borrower_domain);

            $allowed = $isCertifiedWithNoDomain;

            return $allowed;
        }


        // --- Check 3: Match by partner_subdomain (always allowed, regardless of active_custom_url) ---
        $partnerBySubdomain = Partner::where('partner_subdomain', $domain)->first();
        if ($partnerBySubdomain && $user->partner_id == $partnerBySubdomain->id) {
            return true;
        }

        // --- Check 4: Match by partner_domain (only allowed when active_custom_url is enabled) ---
        $partnerByCustomDomain = Partner::where('partner_domain', $domain)
                                        ->orWhere('borrower_domain', $domain)
                                        ->first();

        if ($partnerByCustomDomain && $user->partner_id == $partnerByCustomDomain->id) {
            // Certified partners do not have custom domains — they are always allowed to log in
            if ($partnerByCustomDomain->partner_type === Partner::PARTNER_TYPE_CERTIFIED) {
                return true;
            }

            // Custom domain login is gated behind active_custom_url
            if ($partnerByCustomDomain->active_custom_url) {
                return true;
            }

            // Custom domain exists but is not yet activated — deny
            return false;
        }

        return false;
    }

    /**
     * Get the error message for unauthorized domain access.
     */
    public function getUnauthorizedMessage(User $user, string $domain): string
    {
        return __('auth.invalid_login');
    }
}