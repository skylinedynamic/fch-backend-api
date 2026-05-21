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
        $original = $value;

        $value = preg_replace('#^https?://#i', '', trim($value));
        $value = strtolower(explode('/', $value)[0]);
        $value = preg_replace('/^www\./i', '', $value);

        if ($original !== $value) {
            Log::debug('[PortalIsolation] normalizeDomain', [
                'original'   => $original,
                'normalized' => $value,
            ]);
        }

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

        Log::debug('[PortalIsolation] resolveDomain', [
            'origin'   => $origin,
            'x_domain' => $xDomain,
            'host'     => $host,
            'raw'      => $raw,
            'resolved' => $resolved,
        ]);

        return $resolved;
    }

    /**
     * Check if a user is allowed to access/login via the current domain.
     */
    public function isUserAllowedOnDomain(User $user, string $domain): bool
    {
        $context = [
            'user_id'    => $user->id,
            'user_email' => $user->email,
            'domain_raw' => $domain,
        ];

        // --- Local env bypass ---
        if (app()->environment('local')) {
            Log::debug('[PortalIsolation] ALLOWED — local environment bypass', $context);
            Log::info('[PortalIsolation] Return 1: Local environment bypass', $context);
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


        // --- Check 1: Reserved partner portal ---
        if ($domain === $partnerPortal) {
            $allowed = (bool) $isUserHost;
            Log::info('[PortalIsolation] CHECK partner_portal', array_merge($context, [
                'result'  => $allowed ? 'ALLOWED' : 'DENIED',
                'reason'  => $allowed ? 'user is host' : 'user is not host',
            ]));
            Log::info('[PortalIsolation] Return 2: Reserved partner portal', array_merge($context, [
                'allowed' => $allowed,
            ]));
            return $allowed;
        }

        // --- Check 2: Reserved borrower portal ---
        if ($domain === $borrowerPortal) {
            $isCertifiedWithNoDomain = $userPartner
                && $userPartner->partner_type === Partner::PARTNER_TYPE_CERTIFIED
                && empty($userPartner->borrower_domain);

            $allowed = $isUserHost || $isCertifiedWithNoDomain;

            Log::info('[PortalIsolation] CHECK borrower_portal', array_merge($context, [
                'result'                   => $allowed ? 'ALLOWED' : 'DENIED',
                'is_certified_no_domain'   => $isCertifiedWithNoDomain,
                'reason'                   => $isUserHost
                                                ? 'user is host'
                                                : ($isCertifiedWithNoDomain
                                                    ? 'certified partner with no borrower domain'
                                                    : 'not host and not certified without domain'),
            ]));
            Log::info('[PortalIsolation] Return 3: Reserved borrower portal', array_merge($context, [
                'allowed' => $allowed,
            ]));

            return $allowed;
        }

        // --- Check 3: Other reserved domains ---
        if (in_array($domain, $reservedNormalized, strict: true)) {
            Log::info('[PortalIsolation] CHECK other_reserved — ALLOWED', array_merge($context, [
                'reason' => 'domain is in reserved list (API, website, etc)',
            ]));
            Log::info('[PortalIsolation] Return 4: Other reserved domains', $context);
            return true;
        }

        // --- Check 4: Custom domain lookup ---
        $cacheKey   = "domain_tenant:{$domain}";
        $cacheHit   = Cache::has($cacheKey);

        $partnerDomainOwner = Cache::remember(
            $cacheKey,
            now()->addMinutes(10),
            function () use ($domain, $context) {
                // Primary: fast indexed lookup
                $partner = Partner::where('partner_domain', $domain)
                                  ->orWhere('borrower_domain', $domain)
                                  ->first();

                if ($partner) {
                    Log::debug('[PortalIsolation] domain_lookup — indexed hit', array_merge($context, [
                        'matched_partner_id' => $partner->id,
                        'match_type'         => 'indexed_query',
                    ]));
                    return $partner;
                }

                Log::debug('[PortalIsolation] domain_lookup — indexed miss, trying fallback', $context);

                // Fallback: in-memory normalization for legacy dirty rows
                $partner = Partner::whereNotNull('partner_domain')
                                  ->orWhereNotNull('borrower_domain')
                                  ->get()
                                  ->first(function (Partner $p) use ($domain) {
                                      return $this->normalizeDomain((string) $p->partner_domain) === $domain
                                          || $this->normalizeDomain((string) $p->borrower_domain) === $domain;
                                  });

                if ($partner) {
                    Log::warning('[PortalIsolation] domain_lookup — fallback hit (dirty DB row)', array_merge($context, [
                        'matched_partner_id'  => $partner->id,
                        'stored_partner_domain'  => $partner->partner_domain,
                        'stored_borrower_domain' => $partner->borrower_domain,
                        'action_needed'       => 'normalize this row in DB to avoid full table scan',
                    ]));
                } else {
                    Log::debug('[PortalIsolation] domain_lookup — no match found', $context);
                }

                return $partner;
            }
        );


        // --- Check 5: Domain owner found ---
        if ($partnerDomainOwner) {
            $allowed = $isUserHost || $user->partner_id == $partnerDomainOwner->id;

            Log::info('[PortalIsolation] CHECK custom_domain', array_merge($context, [
                'result'             => $allowed ? 'ALLOWED' : 'DENIED',
                'domain_owner_id'    => $partnerDomainOwner->id,
                'user_partner_id'    => $user->partner_id,
                'reason'             => $isUserHost
                                            ? 'user is host'
                                            : ($allowed ? 'user belongs to domain owner' : 'user does not belong to domain owner'),
            ]));
            Log::info('[PortalIsolation] Return 5: Domain owner found', array_merge($context, [
                'allowed' => $allowed,
            ]));

            return $allowed;
        }

        // --- Check 6: Unknown domain ---
        Log::warning('[PortalIsolation] CHECK unknown_domain', array_merge($context, [
            'result' => $isUserHost ? 'ALLOWED' : 'DENIED',
            'reason' => $isUserHost
                            ? 'unknown domain but user is host'
                            : 'domain not found in reserved list or partners table',
        ]));
        Log::info('[PortalIsolation] Return 6: Unknown domain', array_merge($context, [
            'allowed' => (bool) $isUserHost,
        ]));

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