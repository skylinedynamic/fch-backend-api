<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use App\Models\Borrower\Borrower;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/common/* is the partner portal's API. A borrower's token is a valid
 * api token as well, so this keeps borrowers to the few /common routes the
 * borrower portal (borrower-fch) actually calls, and turns them away from the
 * rest - users, employees, roles, leads, settings and the like. Also on the
 * partner-only routes outside /common (POST /api/v1/notifications/test-email).
 *
 * A route the borrower portal starts calling has to be added here.
 */
class RejectBorrowersFromPartnerPortal
{
    /** "METHOD uri" of every /common route the borrower portal uses. */
    public const BORROWER_ROUTES = [
        'POST api/v1/common/appointments/fetch-all',
        'POST api/v1/common/appointments/check-availability',
        'POST api/v1/common/appointments/fetch-selectable-users',
        'POST api/v1/common/appointments',
        'GET api/v1/common/appointments/{appointment}',
        'PUT api/v1/common/appointments/{appointment}',
        'PATCH api/v1/common/appointments/{appointment}',
        'DELETE api/v1/common/appointments/{appointment}',
        'POST api/v1/common/appointments/{id}/cancel',
        'GET api/v1/common/calendly/status',
        'GET api/v1/common/calendly/event-types',
        'POST api/v1/common/calendly/available-times',
        'POST api/v1/common/calendly/book',
        // Sync now on the borrower's Appointments page: their partner's Calendly (checked in the service).
        'POST api/v1/common/calendly/sync',
        // Their own Calendly: connect (through their partner's Calendly app) and disconnect.
        'POST api/v1/common/calendly/connect',
        'DELETE api/v1/common/calendly/connection',
        'GET api/v1/common/refresh-user',
        'GET api/v1/common/partner-payment-providers',
        'GET api/v1/common/borrowers-documents/download/{documentId}',
        'GET api/v1/common/funding-qualification/{fundingId}/readiness-factors',
        'POST api/v1/common/invoices/{id}/send',
        'GET api/v1/common/request-application/funding-application/{id}',
        'GET api/v1/common/notifications',
        'GET api/v1/common/notifications/unread-count',
        'POST api/v1/common/notifications/read-all',
        'POST api/v1/common/notifications/{id}/read',
        'DELETE api/v1/common/notifications/{id}',
        'POST api/v1/common/payments/{id}/approve',
        'POST api/v1/common/payments/{id}/reject',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->entity_type !== Borrower::class) {
            return $next($request);
        }

        $route = $request->route();
        $key = $request->method() . ' ' . ($route ? $route->uri() : '');

        if (in_array($key, self::BORROWER_ROUTES, true)) {
            return $next($request);
        }

        return ApiResponse::forbidden(__('auth.access_denied_partner_portal'));
    }
}
