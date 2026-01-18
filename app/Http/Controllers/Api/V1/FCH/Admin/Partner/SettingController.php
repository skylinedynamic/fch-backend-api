<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Partner;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\FCH\Setting\SettingResource;
use App\Services\FCH\Admin\Setting\SettingService;
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
            return response()->json(['message' => 'Partner ID is required.'], 422);
        }

        $setting = $this->settingService->updateOrCreate($request->validated(), $partnerId);

        return response()->json([
            'message' => 'Settings saved successfully.',
            'data' => new SettingResource($setting),
        ]);
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
            return response()->json(['message' => 'Partner ID is required.'], 422);
        }

        $setting = $this->settingService->getSettings($partnerId);

        if (!$setting) {
            return response()->json(['message' => 'Settings not found.'], 404);
        }

        return response()->json([
            'message' => 'Settings retrieved successfully.',
            'data' => new SettingResource($setting),
        ]);
    }
}
