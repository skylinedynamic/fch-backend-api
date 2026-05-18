<?php

namespace App\Http\Middleware;

use App\Models\Partner\Partner;
use App\Services\Global\Common\PortalIsolationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    protected $portalIsolationService;

    public function __construct(PortalIsolationService $portalIsolationService)
    {
        $this->portalIsolationService = $portalIsolationService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Only enforce domain isolation in production
        if (!app()->isProduction()) {
            return $next($request);
        }

        // 2. Only enforce domain isolation for authenticated users
        if (!Auth::check()) {
            return $next($request);
        }

        $user = Auth::user();
        try {
            $domain = $this->portalIsolationService->resolveDomain($request);

            // 3. Validate domain access for the authenticated user
            if (!$this->portalIsolationService->isUserAllowedOnDomain($user, $domain)) {
                return response()->json([
                    'status' => 'error',
                    'message' => $this->portalIsolationService->getUnauthorizedMessage($user, $domain),
                ], 403);
            }
        } catch (\Throwable $e) {
            Log::error('EnsureTenantAccess Error: ' . $e->getMessage(), [
                'user_id' => $user->id ?? 'null',
                'trace' => $e->getTraceAsString()
            ]);
            // Optional: return $next($request) in case of failure to prevent total lockout,
            // or let it crash to see the log. Let's let it crash but log it first.
            throw $e;
        }

        return $next($request);
    }
}
