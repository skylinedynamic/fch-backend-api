<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Resource;

use App\Http\Controllers\Controller;
use App\Services\Global\Resource\ResourceCommonService;
use App\Http\Resources\FCH\Resource\DetailsResource;
use App\Helpers\ApiResponse;

class ResourceController extends Controller
{
    private $commonService;

    public function __construct(ResourceCommonService $commonService)
    {
        $this->commonService = $commonService;
    }

    public function index()
    {
        $groupedResources = $this->commonService->getGroupedResources();

        $response = [];
        foreach ($groupedResources as $type => $group) {
            $response[$type] = DetailsResource::collection($group);
        }

        return ApiResponse::success(
            $response,
            __('resource.fetched')
        );
    }

    public function show(int $id)
    {
        $resource = $this->commonService->getById($id);

        return ApiResponse::success(
            new DetailsResource($resource),
            __('resource.fetched')
        );
    }

    public function download(int $id)
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
}
