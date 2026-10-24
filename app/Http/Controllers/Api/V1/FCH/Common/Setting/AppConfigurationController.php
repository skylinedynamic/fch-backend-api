<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Setting;

use App\Http\Controllers\Controller;
use App\Traits\ResolvesConfigPartner;
use App\Services\FCH\Partner\PartnerConfigurationChangeRecorder;
use App\Models\Common\AppConfiguration;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use App\Models\Partner\Partner;
use Illuminate\Support\Facades\Cache;
use App\Services\FCH\Common\Setting\AppConfigurationService;
use App\Http\Resources\FCH\Common\AppConfigurationResource;
use App\Http\Requests\Api\V1\FCH\Common\Setting\GetAppConfigurationRequest;
use App\Http\Requests\Api\V1\FCH\Common\Setting\StoreAppConfigurationRequest;

class AppConfigurationController extends Controller
{
    use ResolvesConfigPartner;

    protected AppConfigurationService $configService;

    public function __construct(AppConfigurationService $configService)
    {
        $this->configService = $configService;
    }

    /**
     * Get app configuration(s) for the current authenticated user's scope.
     * Validated via GetAppConfigurationRequest.
     */
    public function index(GetAppConfigurationRequest $request)
    {
        $partnerId = $this->partnerId($request);

        try {
            $user = auth()->user();
            $partnerId ??= $user ? $user->partner_id : getPartnerIdFromOrigin();

            $module = $request->input('module');
            $key = $request->input('key');

            if ($key) {
                if (is_array($key)) {
                    $configs = $this->configService->getMultiple($key, $module, $partnerId);
                    return ApiResponse::success(new AppConfigurationResource($configs));
                }

                $value = $this->configService->getForScreen($key, $module, $partnerId);
                return ApiResponse::success([
                    'key' => $key,
                    'value' => $value
                ]);
            }

            $configs = $this->configService->getByModule($module, $partnerId);
            return ApiResponse::success(new AppConfigurationResource($configs));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.retrieved_failed'));
        }
    }

    /**
     * Save app configuration(s) for the current authenticated user's scope.
     * Validated via StoreAppConfigurationRequest.
     */
    public function store(StoreAppConfigurationRequest $request)
    {
        $partnerId = $this->partnerId($request);

        try {
            $user = auth()->user();
            $partnerId ??= $user->partner_id;
            $module = $request->input('module');

            // For the activity log of a change made for another partner.
            $before = $this->namesConfigPartner($request) ? $this->configService->getByModule($module, $partnerId) : [];

            if ($request->has('key')) {
                $key = $request->input('key');
                $value = $request->input('value');

                $this->configService->setFromScreen($key, $value, $module, $partnerId);
            } else {
                $settings = $request->input('settings') ?? $request->except(['module', 'partner_id']);

                foreach ((array) $settings as $key => $value) {
                    $this->configService->setFromScreen($key, $value, $module, $partnerId);
                }
            }

            // Cache::forget("mail_branding_" . ($partnerId ?? 'default'));

            // partner_id is only accepted for branding (see partnerId()).
            if ($this->namesConfigPartner($request)) {
                $after = $request->has('key')
                    ? [$request->input('key') => $request->input('value')]
                    : (array) ($request->input('settings') ?? $request->except(['module', 'partner_id']));

                $this->recordConfigChange(
                    $request,
                    PartnerConfigurationChangeRecorder::AREA_BRANDING,
                    'updated the branding',
                    PartnerConfigurationChangeRecorder::diff(array_intersect_key($before, $after), $after)
                );
            }

            return ApiResponse::success(null, __('app_config.saved_successfully'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.updated_failed'));
        }
    }

    /**
     * The partner the host names with `partner_id` (Partner profile ›
     * Configuration), or null for the caller's own. Only branding may be read
     * or saved for another partner; naming one for any other module is refused.
     */
    private function partnerId(Request $request): ?int
    {
        if (!$this->namesConfigPartner($request)) {
            return null;
        }

        abort_unless($request->input('module') === AppConfiguration::MODULE_BRANDING, 403, __('partner.configuration_forbidden'));

        return $this->configPartnerId($request);
    }
}
