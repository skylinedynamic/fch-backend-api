<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Resource;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\FCH\Admin\Resource\ResourceService;
use App\Http\Requests\Api\V1\FCH\Admin\Resource\StoreResourceRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Resource\UpdateResourceRequest;
use App\Http\Resources\FCH\Resource\DetailsResource;
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
            $response[$type] = DetailsResource::collection($group);
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
        // Not strictly needed right now if index returns all, but good to have
        $resource = \App\Models\Resource\Resource::findOrFail($id);

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

    public function download(int $id)
    {
        $resource = \App\Models\Resource\Resource::find($id);

        if (!$resource) {
            return ApiResponse::notFound('Resource not found');
        }

        if (!$resource->file) {
            return ApiResponse::notFound('File path not found for this resource');
        }

        // Accurately normalize and resolve the path
        $rawPath = ltrim(parse_url($resource->file, PHP_URL_PATH) ?? '', '/');
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        
        $cleanPath = $rawPath;
        // Accurate logic: if not found at raw path, check the ID-specific folder
        if (!$disk->exists($cleanPath)) {
            $idPrefixedPath = "resources/{$resource->id}/" . $cleanPath;
            if ($disk->exists($idPrefixedPath)) {
                $cleanPath = $idPrefixedPath;
            }
        }

        if (!$disk->exists($cleanPath)) {
            return ApiResponse::notFound('File not found on server at: ' . $cleanPath);
        }

        // Resolve absolute path accurately
        $absolutePath = $disk->path($cleanPath);
        \Illuminate\Support\Facades\Log::info("Downloading resource: " . $absolutePath);

        if (!file_exists($absolutePath)) {
            return ApiResponse::notFound('File not found at physical location');
        }

        $mimeType = mime_content_type($absolutePath);
        $documentName = $resource->title;
        $extension = pathinfo($absolutePath, PATHINFO_EXTENSION);

        if ($extension && !str_ends_with(strtolower($documentName), '.' . strtolower($extension))) {
            $documentName .= '.' . $extension;
        }

        return response()->download($absolutePath, $documentName, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="' . $documentName . '"',
        ]);
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
