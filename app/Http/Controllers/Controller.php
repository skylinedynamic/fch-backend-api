<?php

namespace App\Http\Controllers;

abstract class Controller
{
    protected function checkWhiteLabelConfiguration()
    {
        $user = auth()->user();
        if ($user && $user->partner && $user->partner->partner_type === \App\Models\Partner\Partner::PARTNER_TYPE_WHITE_LABEL) {
            $partnerId = $user->partner_id;

            $isEmailFresh = \App\Models\Setting\EmailConfiguration::where('partner_id', $partnerId)
                ->where('is_active', true)->exists();

            $isBrandingFresh = \App\Models\Common\AppConfiguration::where('entity_id', $partnerId)
                ->where('entity_type', \App\Models\Partner\Partner::class)
                ->whereIn('key', ['site_name', 'primary_logo'])->exists();

            if (!$isEmailFresh || !$isBrandingFresh) {
                $response = response()->json([
                    'status' => false,
                    'message' => 'Action Required: Please complete your system configuration (Branding and Email) to unlock these features.',
                    'requires_configuration' => true
                ], 403);
                throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
            }
        }
    }
}
