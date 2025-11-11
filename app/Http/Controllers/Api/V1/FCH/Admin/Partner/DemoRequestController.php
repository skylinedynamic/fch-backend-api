<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Partner;

use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\DemoRequest\DemoRequest;
use App\Services\FCH\Partner\DemoRequestService;
use App\Http\Resources\FCH\Partner\DemoRequestResource;
use App\Http\Requests\Api\V1\FCH\Admin\DemoRequest\DemoRequestStoreOrUpdateRequest;

class DemoRequestController extends Controller
{
    protected DemoRequestService $demoRequestService;

    public function __construct(DemoRequestService $demoRequestService)
    {
        $this->demoRequestService = $demoRequestService;
    }

    public function index(Request $request)
    {
        $demoRequests = $this->demoRequestService->getAllDemoRequests($request);

        $resource = DemoRequestResource::collection($demoRequests)->response()->getData(true);

        // Flatten pagination structure
        $formatted = [
            'data' => $resource['data'],
            'links' => $resource['links'],
            'meta' => $resource['meta'],
        ];

        return ApiResponse::success(
            $formatted,
            __('demo_requests.success.retrieved')
        );
    }

    public function store(DemoRequestStoreOrUpdateRequest $request)
    {
        try {
            $payload = $request->validated();
            $demo = $this->demoRequestService->store($payload, $request->user());

            return ApiResponse::success([
            'data' => new DemoRequestResource($demo)
        ], __('demo_requests.success.created'));
        } catch (\Throwable $e) {
            Log::error('DemoRequest store error', ['error' => $e->getMessage()]);
            return ApiResponse::error(__('demo_requests.error.create_failed'), 500);
        }
    }

    // public function update(DemoRequestStoreOrUpdateRequest $request, $id)
    // {
    //     try {
    //         $demo = DemoRequest::where('id', $id)
    //             ->firstOrFail();

    //         $payload = $request->validated();
    //         $updated = $this->demoRequestService->update($demo, $payload, $request->user());

    //         return ApiResponse::success(new DemoRequestResource($updated), __('demo_requests.success.updated'));
    //     } catch (\Throwable $e) {
    //         Log::error('DemoRequest update error', ['error' => $e->getMessage()]);
    //         return ApiResponse::error(__('demo_requests.error.update_failed'), 500);
    //     }
    // }

    public static function successWithMeta($data, $meta = [], $message = null, $status = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
        ], $status);
    }
}
