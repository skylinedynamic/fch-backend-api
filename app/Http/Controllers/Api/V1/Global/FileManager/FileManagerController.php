<?php

namespace App\Http\Controllers\Api\V1\Global\FileManager;

use App\Http\Requests\Api\V1\Global\FileManager\CreateFolderRequest;
use App\Http\Requests\Api\V1\Global\FileManager\DeleteItemsRequest;
use App\Http\Requests\Api\V1\Global\FileManager\ListDocumentsRequest;
use App\Http\Requests\Api\V1\Global\FileManager\PasteItemsRequest;
use App\Http\Requests\Api\V1\Global\FileManager\RenameItemRequest;
use App\Http\Requests\Api\V1\Global\FileManager\UploadFileRequest;
use App\Services\Global\FileManager\FileManagerService;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

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
        $section = $request->input('section');
        $path = $request->input('path', '/');

        $data = $this->fileManagerService->listDocuments($section, $path);

        return response()->json(['data' => $data]);
    }

    /**
     * Upload file
     */
    public function upload(UploadFileRequest $request): JsonResponse
    {
        $section = $request->input('section');
        $path = $request->input('path');
        $file = $request->file('file');

        $data = $this->fileManagerService->uploadFile($section, $path, $file);

        return response()->json(['data' => $data]);
    }

    /**
     * Create folder
     */
    public function createFolder(CreateFolderRequest $request): JsonResponse
    {
        $section = $request->input('section');
        $path = $request->input('path');
        $name = $request->input('name');

        try {
            $data = $this->fileManagerService->createFolder($section, $path, $name);
            return response()->json(['data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 400);
        }
    }

    /**
     * Rename folder or file
     */
    public function rename(RenameItemRequest $request): JsonResponse
    {
        $section = $request->input('section');
        $path = $request->input('path');
        $newName = $request->input('newName');

        try {
            $data = $this->fileManagerService->renameItem($section, $path, $newName);
            return response()->json(['data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 400);
        }
    }

    /**
     * Delete file or folder
     */
    public function delete(DeleteItemsRequest $request): JsonResponse
    {
        $section = $request->input('section');
        $paths = $request->input('paths');

        $this->fileManagerService->deleteItems($section, $paths);

        return response()->json(['success' => true]);
    }

    /**
     * Paste file or folder
     */
    public function paste(PasteItemsRequest $request): JsonResponse
    {
        $section = $request->input('section');
        $files = $request->input('files');
        $destinationPath = $request->input('destinationPath');
        $operation = $request->input('operation');

        $results = $this->fileManagerService->pasteItems($section, $files, $destinationPath, $operation);

        return response()->json(['data' => $results]);
    }
}
