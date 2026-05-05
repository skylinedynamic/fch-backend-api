<?php

namespace App\Http\Controllers\Api\V1\Borrower\Resource;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Borrower\Resource\ResourceResource;
use App\Http\Resources\FCH\Resource\DetailsResource;
use App\Services\Global\Resource\ResourceCommonService;

class ResourceController extends Controller
{
    protected $commonService;

    public function __construct(ResourceCommonService $commonService)
    {
        $this->commonService = $commonService;
    }

    /**
     * Get resources
     */
    public function index()
    {
        $groupedResources = $this->commonService->getGroupedResources();

        $data = [];
        foreach ($groupedResources as $resourceType => $items) {
            $data[$resourceType] = ResourceResource::collection($items);
        }

        return ApiResponse::success(
            $data,
            __('Resources retrieved successfully')
        );
    }

    /**
     * Download resource
     */
    public function download($id)
    {
        $download = $this->commonService->downloadResource($id);

        if ($download === null) {
            return ApiResponse::error(__('No file associated with this resource'));
        }

        if ($download === false) {
            return ApiResponse::error(__('File not found on server'));
        }

        return $download;
    }

    /**
     * Get single resource detail
     */
    public function show($id)
    {
        $resource = $this->commonService->getById($id);

        return ApiResponse::success(
            new DetailsResource($resource),
            __('Resource detail retrieved successfully')
        );
    }
}

