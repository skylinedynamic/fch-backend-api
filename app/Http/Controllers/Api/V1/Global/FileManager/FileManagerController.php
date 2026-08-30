<?php

namespace App\Http\Controllers\Api\V1\Global\FileManager;

use App\Http\Requests\Api\V1\Global\FileManager\CreateFolderRequest;
use App\Http\Requests\Api\V1\Global\FileManager\DeleteItemsRequest;
use App\Http\Requests\Api\V1\Global\FileManager\DownloadFileRequest;
use App\Http\Requests\Api\V1\Global\FileManager\ListDocumentsRequest;
use App\Http\Requests\Api\V1\Global\FileManager\PasteItemsRequest;
use App\Http\Requests\Api\V1\Global\FileManager\RenameItemRequest;
use App\Http\Requests\Api\V1\Global\FileManager\UploadFileRequest;
use App\Services\Global\FileManager\FileManagerService;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Http\Resources\Global\FileManager\FileManagerResource;

class FileManagerController extends Controller
{
    protected $fileManagerService;

    public function __construct(FileManagerService $fileManagerService)
    {
        $this->fileManagerService = $fileManagerService;
    }

    /**
     * List FileManager documents
     */
    public function index(ListDocumentsRequest $request): JsonResponse
    {
        $path = $request->input('path', '/');

        $data = $this->fileManagerService->listDocuments($path, $request);

        return ApiResponse::success(FileManagerResource::collection($data), 'Files retrieved successfully.');
    }

    /**
     * Upload file
     */
    public function upload(UploadFileRequest $request): JsonResponse
    {
        $path = $request->input('path');
        $file = $request->file('file');

        $data = $this->fileManagerService->uploadFile($path, $file, $request);

        return ApiResponse::success(new FileManagerResource($data), 'File uploaded successfully.');
    }

    /**
     * Create folder
     */
    public function createFolder(CreateFolderRequest $request): JsonResponse
    {
        $path = $request->input('path');
        $name = $request->input('name');

        try {
            $data = $this->fileManagerService->createFolder($path, $name, $request);
            return ApiResponse::success(new FileManagerResource($data), 'Folder created successfully.');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    /**
     * Rename folder or file
     */
    public function rename(RenameItemRequest $request): JsonResponse
    {
        $path = $request->input('path');
        $newName = $request->input('newName');

        try {
            $data = $this->fileManagerService->renameItem($path, $newName, $request);
            return ApiResponse::success(new FileManagerResource($data), 'Item renamed successfully.');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    /**
     * Delete file or folder
     */
    public function delete(DeleteItemsRequest $request): JsonResponse
    {
        $paths = $request->input('paths');

        $this->fileManagerService->deleteItems($paths, $request);

        return response()->json([
            'status' => true,
            'message' => 'Items deleted successfully.',
            'success' => true
        ]);
    }

    /**
     * Paste file or folder
     */
    public function paste(PasteItemsRequest $request): JsonResponse
    {
        $files = $request->input('files');
        $destinationPath = $request->input('destinationPath');
        $operation = $request->input('operation');

        $results = $this->fileManagerService->pasteItems($files, $destinationPath, $operation, $request);

        return ApiResponse::success(FileManagerResource::collection($results), 'Items pasted successfully.');
    }

    /**
     * Download file
     */
    public function download(DownloadFileRequest $request)
    {
        $path = $request->input('path');

        return $this->fileManagerService->downloadFile($path, $request);
    }

    /**
     * Get folder tree
     */
    public function tree(ListDocumentsRequest $request): JsonResponse
    {
        $path = $request->input('path', '/');

        $data = $this->fileManagerService->getFolderTree($path, $request);

        return ApiResponse::success($data, 'Folder tree retrieved successfully.');
    }
}
