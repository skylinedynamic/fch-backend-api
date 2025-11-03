<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Partner;

use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\FCH\Partner\DemoRequestService;
use App\Http\Resources\FCH\Partner\DemoRequestResource;

class DemoRequestController extends Controller
{
    protected $demoRequestService;

    public function __construct(DemoRequestService $demoRequestService)
    {
        $this->demoRequestService = $demoRequestService;
    }

    public function index(Request $request)
    {
      
        $demoRequests = $this->demoRequestService->getAllDemoRequests($request);

        return ApiResponse::success([
            'demo-requets' => DemoRequestResource::collection(collect($demoRequests['data'])),
            'meta' => $demoRequests['meta'],
        ], __('demo_requests.success.retrieved'));
    }
}
