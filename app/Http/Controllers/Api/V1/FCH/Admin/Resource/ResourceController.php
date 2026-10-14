<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Resource;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\FCH\Admin\Resource\ResourceService;
use App\Http\Requests\Api\V1\FCH\Admin\Resource\StoreResourceRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Resource\UpdateResourceRequest;
use App\Http\Resources\FCH\Resource\DetailsResource;
use App\Helpers\ApiResponse;

use App\Services\Global\Resource\ResourceCommonService;

class ResourceController extends Controller
{
    private $service;
    private $commonService;

    public function __construct(ResourceService $service, ResourceCommonService $commonService)
    {
        $this->service = $service;
        $this->commonService = $commonService;
    }


    public function getByType($resource_type)
    {
        $resources = $this->service->getByType($resource_type);

        return ApiResponse::success(
            DetailsResource::collection($resources),
            __('resource.fetched')
        );
    }

    public function store(StoreResourceRequest $request)
    {
        $resource = $this->service->create($request->validated());

        return ApiResponse::success(
            new DetailsResource($resource),
            __('resource.created'),
            201
        );
    }

    public function show($id)
    {
        $resource = $this->service->find((int) $id);

        return ApiResponse::success(
            new DetailsResource($resource),
            __('resource.fetched')
        );
    }

    public function update(UpdateResourceRequest $request, $id)
    {
        $resource = $this->service->update($id, $request->validated());

        return ApiResponse::success(
            new DetailsResource($resource),
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


    /**
     * Get the next available ID for resource creation
     */
    public function getNextId()
    {
        $lastId = \App\Models\Resource\Resource::max('id') ?? 0;
        return response()->json(['next_id' => $lastId + 1]);
    }

}