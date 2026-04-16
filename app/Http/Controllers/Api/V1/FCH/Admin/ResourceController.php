<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\FCH\Admin\Resource\ResourceService;
use App\Http\Requests\Api\V1\FCH\Admin\Resource\StoreResourceRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Resource\UpdateResourceRequest;
use App\Http\Resources\Api\V1\FCH\Resource\ResourceResource;
use App\Helpers\ApiResponse;

class ResourceController extends Controller
{
    private $service;

    public function __construct(ResourceService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $groupedResources = $this->service->getAllGrouped();
        
        $response = [];
        foreach ($groupedResources as $type => $group) {
            $response[$type] = ResourceResource::collection($group);
        }

        return ApiResponse::success(
            $response,
            __('resource.fetched')
        );
    }

    public function getByType($resource_type)
    {
        $resources = $this->service->getByType($resource_type);

        return ApiResponse::success(
            ResourceResource::collection($resources),
            __('resource.fetched')
        );
    }

    public function store(StoreResourceRequest $request)
    {
        $resource = $this->service->create($request->validated());

        return ApiResponse::success(
            new ResourceResource($resource),
            __('resource.created'),
            201
        );
    }

    public function show($id)
    {
        // Not strictly needed right now if index returns all, but good to have
        $resource = \App\Models\Resource::findOrFail($id);

        return ApiResponse::success(
            new ResourceResource($resource),
            __('resource.fetched')
        );
    }

    public function update(UpdateResourceRequest $request, $id)
    {
        $resource = $this->service->update($id, $request->validated());

        return ApiResponse::success(
            new ResourceResource($resource),
            __('resource.updated')
        );
    }

    public function destroy($id)
    {
        $this->service->delete($id);

        return ApiResponse::success(
            null,
            __('resource.deleted')
        );
    }
}
