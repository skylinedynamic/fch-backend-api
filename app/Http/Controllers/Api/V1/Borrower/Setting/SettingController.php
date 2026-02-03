<?php

namespace App\Http\Controllers\Api\V1\Borrower\Setting;

use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Services\Borrower\Setting\SettingService;
use App\Http\Resources\FCH\Common\Setting\SettingResource;

class SettingController extends Controller
{
    protected $settingService;

    public function __construct(SettingService $settingService)
    {
        $this->settingService = $settingService;
    }

    /**
     * Get Settings details for the borrower.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        Log::info('show');
        $setting = $this->settingService->getSettings();

        if (!$setting) {
            return ApiResponse::notFound(__('setting.not_found'));
        }

        return ApiResponse::success(new SettingResource($setting), __('setting.retrieved'));
    }
}
