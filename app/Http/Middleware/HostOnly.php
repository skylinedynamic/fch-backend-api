<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes for screens the partner portal shows the host's team only (its
 * HostRoute guard) - the platform-wide catalogues and queues that live under
 * /common rather than /admin. Mirrors the portal's check: the caller's partner
 * is the host.
 */
class HostOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !(bool) $user->partner?->is_host) {
            return ApiResponse::forbidden(__('auth.access_denied_host_only'));
        }

        return $next($request);
    }
}
