<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening headers on every response the app sends (BUG-013).
 *
 * Any site could show the API's own pages in an iframe - the system dashboard
 * and its backup download/restore/delete among them - and lay a page over them
 * to get a click (clickjacking). Responses now may be framed by this site only,
 * and the browser is told not to guess content types.
 *
 * The portals' pages get the same rule from their Nginx server blocks; files
 * under /storage are served by Nginx directly and never reach this.
 */
class SecurityHeaders
{
    /**
     * Routes made to be framed on other sites. They set their own framing rule:
     * the embedded form allows its Allowed Domains, or anywhere (BUG-092).
     */
    public const FRAMEABLE_ROUTES = ['embed-form.show'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if ($request->routeIs(...self::FRAMEABLE_ROUTES)) {
            return $response;
        }

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        // A response with a policy of its own keeps it.
        if (!$response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        }

        return $response;
    }
}
