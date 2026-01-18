<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Setting;

use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\FCH\Admin\Setting\SettingService;
use App\Http\Resources\FCH\Setting\SettingResource;
use App\Http\Requests\Api\V1\FCH\Admin\Setting\StoreSettingRequest;

class SettingController extends Controller
{
    protected $settingService;

    public function __construct(SettingService $settingService)
    {
        $this->settingService = $settingService;
    }

    /**
     * Store or Update Settings.
     *
     * @param StoreSettingRequest $request
     * @return JsonResponse
     */
    public function store(StoreSettingRequest $request): JsonResponse
    {
        // Determine Partner ID:
        // 1. From Authenticated User (if Partner)
        // 2. From Request (if Admin/SuperAdmin acting on behalf)

        $user = auth()->user();
        $partnerId = null;

        if ($user && $user->partner) {
            $partnerId = $user->partner->id;
        } elseif ($request->has('partner_id')) {
            $partnerId = $request->input('partner_id');
        }

        if (!$partnerId) {
            return ApiResponse::validationError(['partner_id' => [__('setting.partner_id_required')]], __('setting.partner_id_required'));
        }

        $setting = $this->settingService->updateOrCreate($request->validated(), $partnerId);

        return ApiResponse::success(new SettingResource($setting), __('setting.saved'));
    }

    /**
     * Get Settings details.
     *
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        $user = auth()->user();
        $partnerId = null;

        if ($user && $user->partner) {
            $partnerId = $user->partner->id;
        } elseif ($request->has('partner_id')) {
            $partnerId = $request->input('partner_id');
        }

        if (!$partnerId) {
            return ApiResponse::validationError(['partner_id' => [__('setting.partner_id_required')]], __('setting.partner_id_required'));
        }

        $setting = $this->settingService->getSettings($partnerId);

        if (!$setting) {
            return ApiResponse::notFound(__('setting.not_found'));
        }

        return ApiResponse::success(new SettingResource($setting), __('setting.retrieved'));
    }
}
