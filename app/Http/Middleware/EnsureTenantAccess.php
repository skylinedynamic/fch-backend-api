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
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // 1. Only enforce domain isolation in production
            if (!app()->isProduction()) {
                return $next($request);
            }

            // 2. Bypass internal resolver, health, and documentation routes
            if ($request->is('resolve-custom-domain*') || $request->is('caddy/*') || $request->is('up') || $request->is('scramble*')) {
                return $next($request);
            }

            // 3. Only enforce domain isolation for authenticated users
            if (!Auth::check()) {
                return $next($request);
            }

            $user = Auth::user();
            $portalIsolationService = app(PortalIsolationService::class);
            $domain = $portalIsolationService->resolveDomain($request);

            // 4. Validate domain access for the authenticated user
            if (!$portalIsolationService->isUserAllowedOnDomain($user, $domain)) {
                return response()->json([
                    'status' => 'error',
                    'message' => $portalIsolationService->getUnauthorizedMessage($user, $domain),
                ], 403);
            }

            return $next($request);
        } catch (\Throwable $e) {
            // FAIL-OPEN: Log the error but don't break the server
            Log::error('EnsureTenantAccess Critical Failure: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'url' => $request->fullUrl(),
                'host' => $request->getHost()
            ]);

            return $next($request);
        }
    }
}
