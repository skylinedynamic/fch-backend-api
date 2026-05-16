<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\CMS;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\FCH\Admin\CMS\UpdateSiteSettingsRequest;
use App\Http\Requests\Api\V1\FCH\Admin\CMS\GetSettingRequest;
use App\Http\Requests\Api\V1\FCH\Admin\CMS\SaveSettingRequest;
use Illuminate\Validation\ValidationException;
use App\Services\FCH\Admin\CMS\SiteSettingsService;

class SiteSettingsController extends Controller
{
    protected SiteSettingsService $siteSettingsService;

    public function __construct(SiteSettingsService $siteSettingsService)
    {
        $this->siteSettingsService = $siteSettingsService;
    }

    public function index()
    {
        try {
            $partnerId = request()->query('entity_id') ?? auth()->user()->partner_id;
            $setting = $this->siteSettingsService->getSettings($partnerId);
            return ApiResponse::success($setting, __('site_settings.retrieved_success'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.retrieved_failed'));
        }
    }

    public function update(UpdateSiteSettingsRequest $request)
    {
        try {
            $data = $request->validated();
            $partnerId = $request->input('entity_id') ?? auth()->user()->partner_id;

            // Pass files if present
            if ($request->hasFile('header_logo')) {
                $data['header_logo'] = $request->file('header_logo');
            }
            if ($request->hasFile('footer_logo')) {
                $data['footer_logo'] = $request->file('footer_logo');
            }
            if ($request->hasFile('partner_logo')) {
                $data['partner_logo'] = $request->file('partner_logo');
            }
            if ($request->hasFile('borrower_logo')) {
                $data['borrower_logo'] = $request->file('borrower_logo');
            }
            if ($request->hasFile('favicon')) {
                $data['favicon'] = $request->file('favicon');
            }
            if ($request->hasFile('partner_favicon')) {
                $data['partner_favicon'] = $request->file('partner_favicon');
            }
            if ($request->hasFile('borrower_favicon')) {
                $data['borrower_favicon'] = $request->file('borrower_favicon');
            }

            $setting = $this->siteSettingsService->updateSettings($partnerId, $data);

            return ApiResponse::success($setting, __('site_settings.updated_success'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.updated_failed'));
        }
    }

    /**
     * Get value of a config key
     */
    public function getSetting(GetSettingRequest $request)
    {
        try {
            $key = $request->key;
            $auth = auth()->user();

            $value = $this->siteSettingsService->getSetting($key, $auth->entity_type, $auth->partner_id);

            return ApiResponse::success([
                'key' => $key,
                'value' => $value
            ]);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.retrieved_failed'));
        }
    }

    /**
     * Save/Toggle value of a config key
     */
    public function saveSetting(SaveSettingRequest $request)
    {
        try {
            $key = $request->key;
            $value = $request->value;
            $auth = auth()->user();

            $this->siteSettingsService->saveSetting($key, $value, $auth->entity_type, $auth->partner_id);

            return ApiResponse::success(null, __('app_config.saved_successfully'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.updated_failed'));
        }
    }
}
