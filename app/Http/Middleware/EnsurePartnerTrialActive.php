<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use App\Models\Borrower\Borrower;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A partner whose free trial has run out may not go on using a session it
 * opened before (R3-BUG-026): sign-in already refuses it, and every request
 * of the partner's team - admin and staff - now answers 401 with the same
 * message, so the portal signs them out (its 401 handler) on the next call,
 * even when the host shortens the trial mid-session.
 *
 * Runs on every api route after the route's auth middleware (see the priority
 * list in bootstrap/app.php). Guests, borrowers (the client portal has its own
 * rules) and the host's team are left alone.
 */
class EnsurePartnerTrialActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->entity_type === Borrower::class) {
            return $next($request);
        }

        $partner = $user->partner;

        if ($partner && $partner->freeTrialExpired()) {
            // Same wording as sign-in: the administrator, not the partner
            // itself (R3-BUG-031).
            return ApiResponse::unauthorized(__('auth.free_trial_expired'));
        }

        return $next($request);
    }
}
