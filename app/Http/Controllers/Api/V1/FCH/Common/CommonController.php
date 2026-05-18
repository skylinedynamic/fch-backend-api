<?php

namespace App\Http\Controllers\Api\V1\FCH\Common;

use App\Http\Controllers\Controller;
use App\Services\FCH\Common\CommonService;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;

use App\Http\Resources\Global\Auth\UserResource;
use Illuminate\Support\Facades\Auth;
use App\Services\FCH\Common\Setting\EmailConfigurationService;
use App\Services\FCH\Common\Setting\GeneralConfigurationsService;

class CommonController extends Controller
{
    public function __construct(protected CommonService $commonService) {}

    /**
     * Get All Users
     */
    public function getAllUsers(Request $request)
    {
        try {
            $users = $this->commonService->getAllUsers();

            return ApiResponse::success(
                $users,
                __('common.users_retrieved')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('common.user_data_refresh_failed'));
        }
    }

    /**
     * Refresh User Data
     */
    public function refreshUser()
    {
        $user = Auth::user();
        $user->is_refresh = false;
        $user->save();

        $relations = [
            'roles' => function ($query) use ($user) {
                $query->where('partner_id', $user->partner_id);
            },
            'roles.permissions',
            'partner.subscription.phases',
        ];

        $user->load($relations);
        
        return ApiResponse::success(
            new UserResource($user),
            __('common.user_data_refreshed')
        );
    }

    /**
     * Get User Count
     */
    public function getUserCount()
    {
        try {
            $data = $this->commonService->getUserCount();
            $message = $data['count'] !== null
                ? __('common.user_count_retrieved')
                : __('common.user_count_not_applicable');

            return ApiResponse::success($data, $message);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('common.user_count_failed'));
        }
    }

    /**
     * Get merged global configurations (Email + Branding) and reset cache flags.
     */
    public function globalConfigurations(EmailConfigurationService $emailService, GeneralConfigurationsService $generalService)
    {
        $user = Auth::user();
        $partnerId = $user->partner_id;

        // Fetch Email Config
        $emailConfig = null;
        if ($partnerId) {
            $configs = $emailService->getConfigurations($partnerId);
            $emailConfig = $configs->where('is_active', true)->first();
            
            // Reset email flag
            \App\Models\Setting\EmailConfiguration::where('partner_id', $partnerId)
                ->where('key', 'is_fresh_email')
                ->update(['value' => '0']);
        }

        // Fetch Branding
        $entityType = $user->entity_type ?? \App\Models\Partner\Partner::class;
        $entityId   = $user->entity_id ?? $partnerId;

        // If it's a borrower, we usually want to show the partner's branding (especially for WL)
        if ($user->user_type === \App\Models\User\User::BORROWER && $partnerId) {
            $partner = $user->partner;
            if ($partner && $partner->partner_type === \App\Models\Partner\Partner::PARTNER_TYPE_WHITE_LABEL) {
                $entityType = \App\Models\Partner\Partner::class;
                $entityId = $partnerId;
            }
        }
        
        $branding = [];
        if ($entityId) {
            $branding = $generalService->show($entityType, $entityId);
        }

        // Reset user settings refresh flag
        $user->is_refresh = false;
        $user->save();

        // Configuration Status Flags for Frontend Guard
        $isEmailFresh = $partnerId ? (\App\Models\Setting\EmailConfiguration::where('partner_id', $partnerId)->where('key', 'is_active')->where('value', '1')->exists()) : true;
        $isBrandingFresh = $partnerId ? (\App\Models\Common\AppConfiguration::where('entity_id', $partnerId)->where('entity_type', \App\Models\Partner\Partner::class)->whereIn('key', ['site_name', 'primary_logo'])->exists()) : true;

        return ApiResponse::success([
            'email_configuration' => $emailConfig,
            'branding' => $branding,
            'status_flags' => [
                'is_fresh_email' => $isEmailFresh ? true : null,
                'is_fresh_settings' => $isBrandingFresh ? true : null,
                'is_fresh_site_settings' => $isBrandingFresh ? true : null,
            ]
        ], __('common.global_configurations_retrieved', [], 'en'));
    }
}
