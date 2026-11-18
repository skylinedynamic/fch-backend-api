<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\OwnerSummary;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\OwnerSummary\FetchOwnerReportSettings;
use App\Http\Requests\Api\V1\FCH\Common\OwnerSummary\UpdateOwnerReportSettings;
use App\Http\Resources\FCH\Common\OwnerSummary\OwnerReportSettingResource;
use App\Services\FCH\Common\OwnerSummary\OwnerReportSettingsService;
use Illuminate\Http\JsonResponse;

/** GET and PUT /api/v1/common/owner-summary/report-settings - who gets the weekly report. */
class OwnerReportSettingsController extends Controller
{
    public function __construct(private readonly OwnerReportSettingsService $service)
    {
    }

    public function show(FetchOwnerReportSettings $request): JsonResponse
    {
        return ApiResponse::success(
            new OwnerReportSettingResource($this->service->forPartner($request->user()->partner_id)),
            __('owner_summary.report_settings_retrieved')
        );
    }

    public function update(UpdateOwnerReportSettings $request): JsonResponse
    {
        $setting = $this->service->save(
            $request->user()->partner_id,
            $request->boolean('enabled'),
            $request->validated('recipients')
        );

        return ApiResponse::success(new OwnerReportSettingResource($setting), __('owner_summary.report_settings_saved'));
    }
}
