<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Comment;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Comment\StoreCommentRequest;
use App\Http\Requests\Api\V1\Comment\UpdateCommentRequest;
use App\Http\Resources\Comment\CommentResource;
use App\Services\Common\Comment\CommentService;
use App\Traits\PaginatesApi;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    use PaginatesApi;

    public function __construct(protected CommentService $commentService)
    {
    }

    public function index(Request $request)
    {
        try {
            $entityType = (string) $request->query('entity_type');
            $entityId = (int) $request->query('entity_id');

            $comments = $this->commentService->list($entityType, $entityId, $request->all());

            return $this->respondWithPaginationResource($comments, CommentResource::class, __('comment.listed'));
        } catch (\Exception $e) {
            $code = $e->getCode();
            if ($code === 422) return ApiResponse::error($e->getMessage(), 422);
            return ApiResponse::serverError(__('comment.list_failed'));
        }
    }

    public function store(StoreCommentRequest $request)
    {
        try {
            $comment = $this->commentService->store($request->validated());
            return ApiResponse::success(new CommentResource($comment), __('comment.created'), 201);
        } catch (\Exception $e) {
            $code = $e->getCode();
            if (in_array($code, [404, 422], true)) return ApiResponse::error($e->getMessage(), $code);
            return ApiResponse::serverError(__('comment.create_failed'));
        }
    }

    public function update(UpdateCommentRequest $request, $id)
    {
        try {
            $comment = $this->commentService->update((int) $id, $request->validated());
            return ApiResponse::success(new CommentResource($comment), __('comment.updated'));
        } catch (\Exception $e) {
            $code = $e->getCode();
            if ($code === 403) return ApiResponse::error($e->getMessage(), 403);
            return ApiResponse::serverError(__('comment.update_failed'));
        }
    }

    public function destroy($id)
    {
        try {
            $this->commentService->delete((int) $id);
            return ApiResponse::success([], __('comment.deleted'));
        } catch (\Exception $e) {
            $code = $e->getCode();
            if ($code === 403) return ApiResponse::error($e->getMessage(), 403);
            return ApiResponse::serverError(__('comment.delete_failed'));
        }
    }
}
