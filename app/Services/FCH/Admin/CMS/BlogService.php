<?php

namespace App\Services\FCH\Admin\CMS;

use App\Models\CMS\Blog;
use App\Models\CMS\BlogCategory;
use App\Models\CMS\BlogTag;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Storage;

class BlogService
{
    public function list(array $filters): array
    {
        $partnerId = auth()->user()->partner_id;
        $query = Blog::with(['category', 'author']);

        if ($partnerId) {
            $query->where('partner_id', $partnerId);
        }

        // Use the scopeFilter for accurate searching
        $query->filter($filters);

        // Sorting
        $sortField = $filters['sort_field'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        
        // Map frontend fields to database columns
        if ($sortField === 'status') {
            $sortField = 'is_published';
        } elseif ($sortField === 'category.name') {
            $sortField = 'category_id';
        }

        // Ensure sort field is valid to prevent SQL injection
        $allowedSortFields = ['title', 'created_at', 'is_published', 'is_featured', 'category_id', 'slug'];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $perPage = $filters['per_page'] ?? 10;
        $blogs = $query->paginate($perPage);

        $statsQuery = Blog::query();
        $catQuery = BlogCategory::query();
        $tagQuery = BlogTag::query();

        if ($partnerId) {
            $statsQuery->where('partner_id', $partnerId);
            $catQuery->where('partner_id', $partnerId);
            $tagQuery->where('partner_id', $partnerId);
        }

        $statistics = [
            'total'      => (clone $statsQuery)->count(),
            'published'  => (clone $statsQuery)->where('is_published', true)->count(),
            'featured'   => (clone $statsQuery)->where('is_featured', true)->count(),
            'categories' => $catQuery->count(),
        ];

        return [
            'paginator'  => $blogs,
            'statistics' => $statistics,
        ];
    }

    public function findById($id)
    {
        return Blog::with(['category'])->find($id);
    }

    public function store(array $data): Blog
    {
        if (isset($data['featured_image']) && $data['featured_image'] instanceof \Illuminate\Http\UploadedFile) {
            $data['featured_image'] = $data['featured_image']->store('blogs', 'public');
        }

        if (isset($data['og_image']) && $data['og_image'] instanceof \Illuminate\Http\UploadedFile) {
            $data['og_image'] = $data['og_image']->store('blogs', 'public');
        }

        $blog = Blog::create($data);

        return $blog->load(['category', 'author']);
    }

    public function update(Blog $blog, array $data)
    {
        if (isset($data['featured_image']) && $data['featured_image'] instanceof \Illuminate\Http\UploadedFile) {
            // Delete old image if exists
            if ($blog->getRawOriginal('featured_image')) {
                Storage::disk('public')->delete($blog->getRawOriginal('featured_image'));
            }
            $data['featured_image'] = $data['featured_image']->store('blogs', 'public');
        }

        if (isset($data['og_image']) && $data['og_image'] instanceof \Illuminate\Http\UploadedFile) {
            // Delete old image if exists
            if ($blog->getRawOriginal('og_image')) {
                Storage::disk('public')->delete($blog->getRawOriginal('og_image'));
            }
            $data['og_image'] = $data['og_image']->store('blogs', 'public');
        }

        $blog->update($data);

        return $blog->load(['category']);
    }

    public function delete(array $ids)
    {
        Blog::whereIn('id', $ids)->delete();
        return ApiResponse::success(null, __('blog.deleted'));
    }

    public function getCategories($partnerId = null)
    {
        $query = BlogCategory::withCount('blogs');
        if ($partnerId) $query->where('partner_id', $partnerId);
        return $query->get();
    }

    public function storeCategory(array $data): BlogCategory
    {
        return BlogCategory::create($data);
    }

    public function updateCategory($id, array $data): ?BlogCategory
    {
        $category = BlogCategory::find($id);
        if (!$category) return null;
        $category->update($data);
        return $category->fresh();
    }

    public function deleteCategories(array $ids)
    {
        BlogCategory::whereIn('id', $ids)->delete();
        return true;
    }

    public function getTags($partnerId = null)
    {
        $query = BlogTag::withCount('blogs');
        if ($partnerId) $query->where('partner_id', $partnerId);
        return $query->get();
    }

    public function storeTag(array $data): BlogTag
    {
        return BlogTag::create($data);
    }

    public function updateTag($id, array $data): ?BlogTag
    {
        $tag = BlogTag::find($id);
        if (!$tag) return null;
        $tag->update($data);
        return $tag->fresh();
    }

    public function deleteTags(array $ids)
    {
        BlogTag::whereIn('id', $ids)->delete();
        return true;
    }
}
