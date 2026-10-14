<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every user belongs to a partner. A signed-in user whose partner cannot be
 * found - no partner_id, or the partner has been deleted - may do nothing.
 *
 * Runs on every api route, after the route's auth middleware (see the priority
 * list in bootstrap/app.php); a route with no signed-in user is left alone.
 */
class EnsureUserHasPartner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->partner) {
            return ApiResponse::forbidden(__('auth.no_partner'));
        }

        return $next($request);
    }
}
