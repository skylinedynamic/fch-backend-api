<?php

namespace App\Http\Controllers\Api\V1\Global\FileManager;

use App\Http\Requests\Api\V1\Global\FileManager\CreateFolderRequest;
use App\Http\Requests\Api\V1\Global\FileManager\DeleteItemsRequest;
use App\Http\Requests\Api\V1\Global\FileManager\DownloadFileRequest;
use App\Http\Requests\Api\V1\Global\FileManager\ListDocumentsRequest;
use App\Http\Requests\Api\V1\Global\FileManager\PasteItemsRequest;
use App\Http\Requests\Api\V1\Global\FileManager\RenameItemRequest;
use App\Http\Requests\Api\V1\Global\FileManager\UploadFileRequest;
use App\Models\Borrower\Borrower;
use App\Services\Global\FileManager\FileManagerService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Http\Resources\Global\FileManager\FileManagerResource;

class FileManagerController extends Controller implements HasMiddleware
{
    protected $fileManagerService;

    public function __construct(FileManagerService $fileManagerService)
    {
        $this->fileManagerService = $fileManagerService;
    }

    /**
     * Every action works below a root folder the request names itself
     * ({entity_type}/{entity_id}, or all of {entity_type} with get_all). A
     * borrower may only ever name their own folder - the only one the borrower
     * portal opens - never another borrower's, nor all of them.
     */
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $request, Closure $next) {
                $user = $request->user();

                if ($user && $user->entity_type === Borrower::class) {
                    $ownFolder = $request->input('entity_type') === 'borrowers'
                        && (int) $request->input('entity_id') === (int) $user->entity_id
                        && !$request->boolean('get_all');

                    if (!$ownFolder) {
                        return ApiResponse::forbidden(__('auth.access_denied_borrower_privileges_required'));
                    }
                }

                return $next($request);
            }),
        ];
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

        // The service reports a missing file or a folder as an exception carrying
        // the status; answered as that status rather than left to become a 500.
        try {
            return $this->fileManagerService->downloadFile($path, $request);
        } catch (\Exception $e) {
            return match ((int) $e->getCode()) {
                404 => ApiResponse::notFound($e->getMessage()),
                400 => ApiResponse::error($e->getMessage(), 400),
                default => throw $e,
            };
        }
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
