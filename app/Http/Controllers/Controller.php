<?php

namespace App\Http\Controllers;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class Controller
{
    protected function checkWhiteLabelConfiguration()
    {
        $route = request()->route();
        if ($route && $route->getActionMethod() !== 'store') {
            return;
        }
        
        $user = auth()->user();
        // The host runs on the platform's own branding and mail, so it is never
        // held to a white-label partner's setup - even though it is seeded as one.
        if ($user && $user->partner && !$user->partner->is_host && $user->partner->partner_type == Partner::PARTNER_TYPE_WHITE_LABEL) {
            $partnerId = $user->partner_id;

            // Mail configuration is not required: until the partner's own is
            // verified its mail goes out with the platform's fallback
            // configuration, and the portal's banner says so on every page.
            $isBrandingFresh = AppConfiguration::where('partner_id', $partnerId)
                ->where('module', AppConfiguration::MODULE_BRANDING)
                ->whereIn('key', AppConfiguration::BRANDING_FRESHNESS_KEYS)->exists();

            if (!$isBrandingFresh) {
                $response = response()->json([
                    'status' => false,
                    'message' => 'Please complete your Branding configuration to unlock these features.',
                    'requires_configuration' => true
                ], 403);
                throw new HttpResponseException($response);
            }
        }
    }
}
