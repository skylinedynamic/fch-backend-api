<?php

namespace App\Http\Controllers;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Models\Setting\EmailConfiguration;
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
        if ($user && $user->partner && $user->partner->partner_type == Partner::PARTNER_TYPE_WHITE_LABEL) {
            $partnerId = $user->partner_id;

            $isEmailFresh = EmailConfiguration::where('partner_id', $partnerId)
                ->where('key', 'is_active')->where('value', '1')->exists();

            $isBrandingFresh = AppConfiguration::where('partner_id', $partnerId)
                ->whereIn('key', ['site_name', 'primary_logo'])->exists();

            if (!$isEmailFresh || !$isBrandingFresh) {
                $response = response()->json([
                    'status' => false,
                    'message' => 'Please complete your system configuration (Branding and Email) to unlock these features.',
                    'requires_configuration' => true
                ], 403);
                throw new HttpResponseException($response);
            }
        }
    }
}
