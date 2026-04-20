<?php

namespace App\Http\Controllers\Api\V1\Borrower\Resource;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Borrower\Resource\ResourceResource;
use App\Services\Borrower\Resource\ResourceService;
use Illuminate\Http\Request;

class ResourceController extends Controller
{
    protected $resourceService;

    public function __construct(ResourceService $resourceService)
    {
        $this->resourceService = $resourceService;
    }

    /**
     * Get resources
     */
    public function index()
    {
        $resources = $this->resourceService->getResources();

        $groupedResources = $resources->groupBy('resource_type');

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
        $resource = \App\Models\Resource\Resource::findOrFail($id);
        $download = $resource->download();

        if ($download === null) {
            return ApiResponse::error(__('No file associated with this resource'));
        }

        if ($download === false) {
            return ApiResponse::error(__('File not found on server'));
        }

        return $download;
    }
}
