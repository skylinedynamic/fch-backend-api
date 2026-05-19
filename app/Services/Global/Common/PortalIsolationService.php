<?php

namespace App\Services\Global\Common;

use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PortalIsolationService
{
    /**
     * Resolve the current domain from the request.
     */
    public function resolveDomain(Request $request): string
    {
        $origin = $request->header('Origin');
        $domain = ($origin && parse_url($origin, PHP_URL_HOST))
            ? parse_url($origin, PHP_URL_HOST)
            : ($request->header('X-Domain') ?: $request->getHost());

        $domain = strtolower(trim($domain));
        return preg_replace('/^www\./', '', $domain);
    }

    /**
     * Check if a user is allowed to access/login via the current domain.
     */
    public function isUserAllowedOnDomain(User $user, string $domain): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        $userPartner = $user->partner;
        $isUserHost = $userPartner && $userPartner->is_host;

        // 1. Resolve reserved domains
        $reserved = config('domains.reserved', []);
        $reservedLower = array_map('strtolower', $reserved);

        $partnerPortal = strtolower(trim($reserved['base_reserved_partner_portal'] ?? ''));
        $borrowerPortal = strtolower(trim($reserved['base_reserved_borrower_portal'] ?? ''));

        // 2. Specific checks for reserved portals
        if ($domain == $partnerPortal) {
            // Reserved Partner Portal is ONLY for host users
            return (bool) $isUserHost;
        }

        if ($domain === $borrowerPortal) {
            // Reserved Borrower Portal is for host users OR certified partners without a custom borrower domain
            $isCertifiedWithNoDomain = $userPartner &&
                $userPartner->partner_type === Partner::PARTNER_TYPE_CERTIFIED &&
                empty($userPartner->borrower_domain);

            return $isUserHost || $isCertifiedWithNoDomain;
        }

        // 3. Bypass other reserved domains (API, website)
        if (in_array($domain, $reservedLower)) {
            return true;
        }

        // 4. Find the partner that owns this custom domain
        $partnerDomainOwner = Cache::remember("domain_tenant:{$domain}", now()->addMinutes(10), function () use ($domain) {
            return Partner::where('partner_domain', $domain)
                ->orWhere('borrower_domain', $domain)
                ->first();
        });

        if ($partnerDomainOwner) {
            // Domain belongs to a specific partner. User must belong to THIS partner (unless Host).
            return $isUserHost || $user->partner_id == $partnerDomainOwner->id;
        }

        // 5. Domain does NOT belong to any partner and is NOT reserved.
        // For strict isolation, only host users can be on unknown domains.
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
