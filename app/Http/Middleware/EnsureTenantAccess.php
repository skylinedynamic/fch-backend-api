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

        // 1. Resolve current domain
        $domain = $request->header('X-Domain') ?: $request->getHost();
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^www\./', '', $domain);

        // 2. Check if this is a Host/Reserved domain (e.g. your main portal or admin site)
        $reserved = config('domains.reserved', []);
        if (in_array($domain, array_map('strtolower', $reserved))) {
            return $next($request);
        }

        // 3. Find the partner that owns this domain
        $partner = Cache::remember("domain_tenant:{$domain}", now()->addMinutes(10), function () use ($domain) {
            return Partner::where('partner_domain', $domain)
                ->orWhere('borrower_domain', $domain)
                ->first();
        });

        // 4. Case A: If some is trying to LOGIN
        if ($partner && ($request->is('*/login') || $request->is('login')) && $request->has('email')) {
            $user = \App\Models\User\User::where('email', $request->email)->first();

            if ($user && (!isset($user->is_host) || $user->is_host != 1)) {
                if ($user->partner_id != $partner->id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Invalid credentials for this domain.',
                    ], 401);
                }
            }
        }

        // 5. Case B: If a user is ALREADY LOGGED IN but on the wrong domain
        if ($partner && Auth::check()) {
            $user = Auth::user();

            // If the user's partner_id doesn't match the current domain's partner
            if ($user->partner_id != $partner->id) {
                // Return 403 or force logout if they are on the wrong domain
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized domain access. Please login through your assigned portal.',
                ], 403);
            }
        }

        // 5. If it's a partner domain but not even a valid partner is found (and it's not a host domain)
        // you might want to block access too, but we'll let it pass for guest routes like login.

        return $next($request);
    }
}
