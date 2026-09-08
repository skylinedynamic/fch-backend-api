<?php

namespace App\Http\Controllers\Api\V1\FCH\Common;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Global\Auth\UserResource;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\Setting\EmailConfiguration;
use App\Models\User\User;
use App\Services\FCH\Common\CommonService;
use App\Services\FCH\Common\Setting\AppConfigurationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CommonController extends Controller
{
    public function __construct(protected CommonService $commonService)
    {
    }

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
            // See AuthService::login() for why '.phases' was dropped.
            'partner.subscription',
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
    public function getTheme(AppConfigurationService $configService)
    {
        $partner = getConfigPartner();
        $branding = $configService->getBrandingSettings($partner->id);

        // Configuration Status Flags for Frontend Guard
        $isEmailFresh = $partner ? (EmailConfiguration::where('partner_id', $partner->id)->where('key', 'is_active')->where('value', '1')->exists()) : true;
        $isBrandingFresh = $partner ? (AppConfiguration::where('partner_id', $partner->id)->where('module', AppConfiguration::MODULE_BRANDING)->whereIn('key', AppConfiguration::BRANDING_FRESHNESS_KEYS)->exists()) : true;

        $isPaymentFresh = false;
        if ($partner) {
            // Find default active provider, or first active provider
            $partnerProvider = PartnerPaymentProvider::where('partner_id', $partner->id)
                ->where('status', true)
                ->first();

            if ($partnerProvider) {
                $config = $partnerProvider->configuration ?? [];
                $paymentMode = $config['payment_mode'] ?? PaymentProvider::MODE_SANDBOX;

                if ($paymentMode) {
                    $isPaymentFresh = true;
                }
            }
        }

        if (auth()->check()) {
            return ApiResponse::success([
                'branding' => $branding,
                'status_flags' => [
                    'is_fresh_email' => $isEmailFresh ? true : null,
                    'is_fresh_settings' => $isBrandingFresh ? true : null,
                    'is_fresh_site_settings' => $isBrandingFresh ? true : null,
                    'is_fresh_payment' => $isPaymentFresh ? true : null,
                ]
            ], __('common.global_configurations_retrieved'));
        } else {
            return ApiResponse::success($branding, __('common.global_configurations_retrieved'));
        }
    }
}
