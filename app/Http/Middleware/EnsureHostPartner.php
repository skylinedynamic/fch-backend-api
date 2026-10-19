<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use App\Models\Borrower\Borrower;
use App\Models\User\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the host partner's own team - its account and its staff.
 *
 * A borrower's user carries its partner's partner_id too, so "the user's
 * partner is the host" alone would let the host's clients through.
 */
class EnsureHostPartner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!self::allows($request->user())) {
            return ApiResponse::error(__('borrower.host_partner_only'), 403);
        }

        return $next($request);
    }

    public static function allows(?User $user): bool
    {
        return $user !== null
            && $user->user_type !== User::BORROWER
            && $user->entity_type !== Borrower::class
            && (bool) $user->partner?->is_host;
    }
}
