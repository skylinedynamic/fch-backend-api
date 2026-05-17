<?php

namespace App\Http\Middleware;

use App\Models\Partner\Partner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only enforce domain isolation in production
        if (!app()->isProduction()) {
            return $next($request);
        }

        // 1. Only enforce domain isolation for authenticated users
        if (!Auth::check()) {
            return $next($request);
        }

        $user = Auth::user();
        $userPartner = $user->partner;
        $isUserHost = $userPartner && $userPartner->is_host == 1;

        // 2. Resolve current domain
        $domain = $request->header('X-Domain') ?: $request->getHost();
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^www\./', '', $domain);

        // 3. Resolve reserved domains and host portals
        $reserved = config('domains.reserved', []);
        $reservedLower = array_map('strtolower', $reserved);

        $partnerPortal = strtolower(trim($reserved['base_reserved_partner_portal']));
        $borrowerPortal = strtolower(trim($reserved['base_reserved_borrower_portal']));
        $hostPortals = [$partnerPortal, $borrowerPortal];

        $isReserved = in_array($domain, $reservedLower);
        $isHostPortal = in_array($domain, $hostPortals);

        // 4. Enforce access control for host portals (only host users are allowed)
        if ($isHostPortal) {
            if (!$isUserHost) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized domain access. Please login through your assigned portal.',
                ], 403);
            }

            return $next($request);
        }

        // Bypassing logic for other host/reserved domains (like the main website or API domain)
        if ($isReserved) {
            return $next($request);
        }

        // 5. Find the partner that owns this domain (tenant portal)
        $partner = Cache::remember("domain_tenant:{$domain}", now()->addMinutes(10), function () use ($domain) {
            return Partner::where('partner_domain', $domain)
                ->orWhere('borrower_domain', $domain)
                ->first();
        });

        // 6. Ensure the authenticated user is on their correct tenant domain
        if ($partner) {
            if (!$isUserHost && $user->partner_id != $partner->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized domain access. Please login through your assigned portal.',
                ], 403);
            }
        }

        return $next($request);
    }
}
