<?php

namespace App\Http\Controllers\Api\V1\Global\Website\CMS;

use App\Http\Controllers\Controller;
use App\Services\Global\Website\CMS\WebsiteBlogService;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    protected $blogService;

    public function __construct(WebsiteBlogService $blogService)
    {
        $this->blogService = $blogService;
    }

    public function index(Request $request)
    {
        $blogs = $this->blogService->list($request);
        return ApiResponse::success($blogs, __('blog.fetched'));
    }

    public function show($slug)
    {
        $blog = $this->blogService->findBySlug($slug);

        if (!$blog) {
            return ApiResponse::notFound(__('blog.not_found'));
        }

        return ApiResponse::success($blog, __('blog.post_fetched'));
    }

    public function categories()
    {
        $categories = $this->blogService->getCategories();
        return ApiResponse::success($categories, __('blog.categories_fetched'));
    }

    public function tags()
    {
        $tags = $this->blogService->getTags();
        return ApiResponse::success($tags, __('blog.tags_fetched'));
    }

    public function latest()
    {
        $blogs = $this->blogService->getLatest();
        return ApiResponse::success($blogs, __('blog.latest_fetched'));
    }
}
