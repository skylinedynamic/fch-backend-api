<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\ScoreConfiguration;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\ScoreConfiguration\UpdateScoreConfigurationRequest;
use App\Http\Resources\FCH\ScoreConfiguration\ScoreConfigurationResource;
use App\Services\FCH\Admin\ScoreConfiguration\ScoreConfigurationService;

class ScoreConfigurationController extends Controller
{
    protected ScoreConfigurationService $scoreConfigService;

    public function __construct(ScoreConfigurationService $scoreConfigService)
    {
        $this->scoreConfigService = $scoreConfigService;
    }

    public function index()
    {
        try {
            $config = $this->scoreConfigService->getAll();
            return ApiResponse::success(
                new ScoreConfigurationResource($config),
                __('Score configurations retrieved successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('Failed to retrieve score configurations'));
        }
    }

    public function update(UpdateScoreConfigurationRequest $request)
    {
        try {
            $this->scoreConfigService->updateAll($request->validated());
            return ApiResponse::success(null, __('Score configurations updated successfully'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('Failed to update score configurations'));
        }
    }
}
