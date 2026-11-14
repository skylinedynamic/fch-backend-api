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
use App\Models\Employee\Employee;
use App\Services\Global\FileManager\FileManagerService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Http\Resources\Global\FileManager\FileManagerResource;
use App\Support\Files\FileManagerFolders;
use App\Support\Files\Folder;

class FileManagerController extends Controller implements HasMiddleware
{
    protected $fileManagerService;

    public function __construct(FileManagerService $fileManagerService)
    {
        $this->fileManagerService = $fileManagerService;
    }

    /**
     * Every action works below a root folder the request names itself
     * ({entity_type}/{entity_id}, or all of {entity_type} with get_all), so
     * every request is checked against the folders the file manager opens
     * (FileManagerFolders) before it reaches an action. A borrower may only
     * ever name their own folder - the only one the borrower portal opens.
     */
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $request, Closure $next) {
                $user = $request->user();

                // No root named at all: each action's request answers that (422).
                if ($user && !$request->filled('entity_type')) {
                    return $next($request);
                }

                if ($user && $user->entity_type === Borrower::class) {
                    if (!self::isBorrowersOwnFolder($user, $request)) {
                        return ApiResponse::forbidden(__('auth.access_denied_borrower_privileges_required'));
                    }
                } elseif (!$user || !self::partnerMayOpen($user, $request)) {
                    return ApiResponse::forbidden(__('filemanager.folder_not_yours'));
                }

                return $next($request);
            }),
        ];
    }

    private static function isBorrowersOwnFolder($user, Request $request): bool
    {
        return $request->input('entity_type') === Folder::BORROWERS
            && self::recordId($request) === (int) $user->entity_id
            && !$request->boolean('get_all');
    }

    /**
     * The partner side (R3-BUG-002). The root is matched exactly against the
     * list, so no spelling of a path (../, ./, a backslash) reaches another
     * folder. Then:
     * - get_all opens a shared library whole, for the host's team only;
     * - a host-only folder opens for the host's team;
     * - a partner's folder for its own team and the host's;
     * - a client's or staff member's folder for the host's team, or the team
     *   of the partner the record belongs to.
     */
    private static function partnerMayOpen($user, Request $request): bool
    {
        $folder = $request->input('entity_type');
        $rule = is_string($folder) ? FileManagerFolders::rule($folder) : null;
        $isHost = (bool) $user->partner?->is_host;

        if ($rule === null) {
            return false;
        }

        if ($request->boolean('get_all')) {
            return $isHost && FileManagerFolders::isShared($folder);
        }

        $id = self::recordId($request);
        if ($id === null) {
            return false;
        }

        if ($isHost) {
            return true;
        }

        return match ($rule) {
            FileManagerFolders::OWN_PARTNER => $id === (int) $user->partner_id,
            FileManagerFolders::OWN_RECORD => self::RECORD_MODELS[$folder]::whereKey($id)
                ->where('partner_id', $user->partner_id)
                ->exists(),
            default => false,
        };
    }

    /** The record a per-record folder belongs to. */
    private const RECORD_MODELS = [
        Folder::BORROWERS => Borrower::class,
        Folder::EMPLOYEES => Employee::class,
    ];

    /** The root's id as a whole number, or null when it is anything else (a path, a sign, a word). */
    private static function recordId(Request $request): ?int
    {
        $id = $request->input('entity_id');

        return (is_int($id) && $id >= 0) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
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
