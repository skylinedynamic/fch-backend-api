<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use App\Helpers\ApiResponse;
use App\Models\User\User;

class BorrowerMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // If user is not authenticated, return unauthorized
        if (!$user) {
            return ApiResponse::error(
                'Unauthorized. Please login first.',
                401
            );
        }

        // Check if user has borrower user type
        if ($user->user_type !== User::USERTYPE['Borrower']) {
            return ApiResponse::error(
                'Access denied. Borrower privileges required.',
                403
            );
        }

        return $next($request);
    }
}