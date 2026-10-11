<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps users.timezone in step with the X-Timezone header the apps send on
 * every request, so anything rendered for the user outside a request (e.g. a
 * reminder pushed over Reverb) uses the same timezone their requests do.
 *
 * Runs after the request, once auth has resolved the user, and writes only
 * when the zone is a real IANA identifier that differs from the stored one.
 */
class RememberUserTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $timezone = $request->header('X-Timezone');

        if (!is_string($timezone) || $timezone === '') {
            return $response;
        }

        // Only a user the route's auth middleware already resolved - an
        // unauthenticated route costs nothing here.
        $guard = auth()->guard();
        $user = $guard->hasUser() ? $guard->user() : null;

        if ($user && $user->timezone !== $timezone && in_array($timezone, timezone_identifiers_list(), true)) {
            $user->forceFill(['timezone' => $timezone])->saveQuietly();
        }

        return $response;
    }
}
