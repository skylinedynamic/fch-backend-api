<?php

namespace App\Http\Controllers\Api\V1\FCH\Common;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Global\Auth\UserResource;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Services\FCH\Common\Setting\EmailConfigurationService;
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
            $users = $this->commonService->getAllUsers(
                $request->integer('partner_id') ?: null,
                $request->integer('include_user_id') ?: null
            );

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

        // Portal chrome, so the host's logo is not inherited. getConfigPartner()
        // has already resolved every partner that trades under the host's brand
        // to the host itself, so a non-host partner here is one branded as
        // itself, and its borrowers must never see the host's mark in the
        // sidebar or on the login screen. See getBrandingSettings().
        $branding = $configService->getBrandingSettings($partner->id, (bool) $partner->is_host);

        // Configuration Status Flags for Frontend Guard
        $isEmailFresh = $partner ? app(EmailConfigurationService::class)->hasAnyConfiguration($partner->id) : true;
        $isBrandingFresh = $partner ? (AppConfiguration::where('partner_id', $partner->id)->where('module', AppConfiguration::MODULE_BRANDING)->whereIn('key', AppConfiguration::BRANDING_FRESHNESS_KEYS)->exists()) : true;

        // Payments are judged on the signed-in user's own partner, not the
        // branding partner above (BUG-115): getConfigPartner() hands a
        // Certified partner the host, whose gateways are always on, so the
        // Configuration Incomplete guard never fired for it. A Certified
        // partner's gateways are saved under its own id.
        $isPaymentFresh = false;
        $paymentPartnerId = auth()->user()?->partner_id ?? $partner?->id;
        if ($paymentPartnerId) {
            // An active linkage always resolves to one mode or the other, so this
            // flag says only that the partner has configured payments at all.
            $isPaymentFresh = PartnerPaymentProvider::where('partner_id', $paymentPartnerId)
                ->where('status', true)
                ->exists();
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
