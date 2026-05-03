<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\CMS;

use App\Http\Controllers\Controller;
use App\Services\FCH\Admin\CMS\BlogService;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\FCH\Admin\CMS\BlogRequest;
use App\Http\Requests\Api\V1\FCH\Admin\CMS\CategoryRequest;
use App\Http\Requests\Api\V1\FCH\Admin\CMS\TagRequest;
use App\Http\Resources\FCH\CMS\BlogResource;
use App\Http\Resources\FCH\CMS\CategoryResource;
use App\Http\Resources\FCH\CMS\TagResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BlogController extends Controller
{
    protected BlogService $blogService;

    public function __construct(BlogService $blogService)
    {
        $this->blogService = $blogService;
    }

    public function index(Request $request)
    {
        $filters = [
            'search' => $request->search,
            'category_id' => $request->category_id,
            'status' => $request->status,
            'is_featured' => $request->is_featured,
            'sort_field' => $request->sort_field,
            'sort_order' => $request->sort_order,
            'per_page' => $request->per_page,
            'partner_id' => $request->user()->partner_id,
        ];
        $result  = $this->blogService->list($filters);
        $blogs   = $result['paginator'];
        $stats   = $result['statistics'];

        return ApiResponse::successWithPaginationAdditional(
            BlogResource::collection($blogs->items())->resolve(),
            [
                'total'        => $blogs->total(),
                'per_page'     => $blogs->perPage(),
                'current_page' => $blogs->currentPage(),
                'last_page'    => $blogs->lastPage(),
            ],
            ['statistics' => $stats]
        );
    }

    public function store(BlogRequest $request)
    {
        $data = $request->validated();

        $data['partner_id'] = $request->user()->partner_id;
        $data['author_id']  = Auth::id();
        $data['created_by'] = Auth::id();

        if (!isset($data['is_published'])) $data['is_published'] = false;
        if ($data['is_published'] && !isset($data['published_at'])) $data['published_at'] = now();

        $blog = $this->blogService->store($data);

        return ApiResponse::success(new BlogResource($blog), __('blog.created'), 201);
    }

    public function show($id)
    {
        $blog = $this->blogService->findById($id);
        if (!$blog) return ApiResponse::notFound(__('blog.not_found'));
        return ApiResponse::success(new BlogResource($blog), __('blog.fetched'));
    }

    public function update(BlogRequest $request, $id)
    {
        $blog = $this->blogService->findById($id);
        if (!$blog) return ApiResponse::notFound(__('blog.not_found'));

        $data = $request->validated();
        $data['updated_by'] = Auth::id();

        $updatedBlog = $this->blogService->update($blog, $data);

        return ApiResponse::success(new BlogResource($updatedBlog), __('blog.updated'));
    }

    public function destroy(Request $request)
    {
        $ids = $request->ids;
        if (!is_array($ids)) $ids = [$ids];
        return $this->blogService->delete($ids);
    }

    public function categories(Request $request)
    {
        $categories = $this->blogService->getCategories($request->user()->partner_id);
        return ApiResponse::success(CategoryResource::collection($categories), __('blog.fetched'));
    }

    public function storeCategory(CategoryRequest $request)
    {
        $data = $request->validated();
        $data['partner_id'] = $request->user()->partner_id;
        $data['created_by'] = Auth::id();

        $category = $this->blogService->storeCategory($data);
        return ApiResponse::success(new CategoryResource($category), __('blog.category_created'), 201);
    }

    public function updateCategory(CategoryRequest $request, $id)
    {
        $data = $request->validated();
        $data['updated_by'] = Auth::id();

        $category = $this->blogService->updateCategory($id, $data);
        if (!$category) return ApiResponse::notFound(__('blog.category_not_found'));
        return ApiResponse::success(new CategoryResource($category), __('blog.category_updated'));
    }

    public function destroyCategory(Request $request)
    {
        $ids = $request->ids;
        if (!is_array($ids)) $ids = [$ids];
        $this->blogService->deleteCategories($ids);
        return ApiResponse::success(null, __('blog.category_deleted'));
    }

    public function tags(Request $request)
    {
        $tags = $this->blogService->getTags($request->user()->partner_id);
        return ApiResponse::success(TagResource::collection($tags), __('blog.fetched'));
    }

    public function storeTag(TagRequest $request)
    {
        $data = $request->validated();
        $data['partner_id'] = $request->user()->partner_id;
        $data['created_by'] = Auth::id();

        $tag = $this->blogService->storeTag($data);
        return ApiResponse::success(new TagResource($tag), __('blog.tag_created'), 201);
    }

    public function updateTag(TagRequest $request, $id)
    {
        $data = $request->validated();
        $data['updated_by'] = Auth::id();

        $tag = $this->blogService->updateTag($id, $data);
        if (!$tag) return ApiResponse::notFound(__('blog.tag_not_found'));
        return ApiResponse::success(new TagResource($tag), __('blog.tag_updated'));
    }

    public function destroyTag(Request $request)
    {
        $ids = $request->ids;
        if (!is_array($ids)) $ids = [$ids];
        $this->blogService->deleteTags($ids);
        return ApiResponse::success(null, __('blog.tag_deleted'));
    }
}
