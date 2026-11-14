<?php

namespace App\Services\FCH\Admin\CMS;

use App\Models\CMS\Blog;
use App\Models\CMS\BlogCategory;
use App\Models\CMS\BlogTag;
use App\Exceptions\BlogTaxonomyInUseException;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Storage;
use App\Support\Files\Disk;
use App\Support\Files\Folder;

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
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        // Map frontend fields to database columns
        if ($sortField === 'status') {
            $sortField = 'is_published';
        }

        // Ensure sort field is valid to prevent SQL injection
        $allowedSortFields = ['title', 'created_at', 'is_published', 'is_featured', 'slug'];
        if ($sortField === 'category.name') {
            // By the category name the column shows, not its id (BUG-184). A
            // subquery rather than a join keeps the filter's columns unambiguous.
            $query->orderBy(
                BlogCategory::select('name')->whereColumn('blog_categories.id', 'blogs.category_id')->limit(1),
                $sortOrder
            );
        } elseif (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        // Rows with the same sort value need a fixed order, or each page query
        // returns them differently and posts repeat or go missing (BUG-185).
        $query->orderBy('blogs.id', 'desc');

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
            $data['featured_image'] = $data['featured_image']->store(Folder::BLOGS, Disk::UPLOADS);
        }

        if (isset($data['og_image']) && $data['og_image'] instanceof \Illuminate\Http\UploadedFile) {
            $data['og_image'] = $data['og_image']->store(Folder::BLOGS, Disk::UPLOADS);
        }

        $blog = Blog::create($data);

        return $blog->load(['category', 'author']);
    }

    public function update(Blog $blog, array $data)
    {
        if (isset($data['featured_image']) && $data['featured_image'] instanceof \Illuminate\Http\UploadedFile) {
            // Delete old image if exists
            if ($blog->getRawOriginal('featured_image')) {
                Storage::disk(Disk::UPLOADS)->delete($blog->getRawOriginal('featured_image'));
            }
            $data['featured_image'] = $data['featured_image']->store(Folder::BLOGS, Disk::UPLOADS);
        }

        if (isset($data['og_image']) && $data['og_image'] instanceof \Illuminate\Http\UploadedFile) {
            // Delete old image if exists
            if ($blog->getRawOriginal('og_image')) {
                Storage::disk(Disk::UPLOADS)->delete($blog->getRawOriginal('og_image'));
            }
            $data['og_image'] = $data['og_image']->store(Folder::BLOGS, Disk::UPLOADS);
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
        // Newest first: Existing Categories shows the latest at the top (BUG-059).
        return $query->latest()->latest('id')->get();
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

    /**
     * All or none: a category still on a blog post is kept, and then nothing in
     * this call is deleted (BUG-059).
     *
     * @throws BlogTaxonomyInUseException
     */
    public function deleteCategories(array $ids)
    {
        $blocked = BlogCategory::whereIn('id', $ids)->has('blogs')->get(['id', 'name'])
            ->map(fn ($category) => ['id' => $category->id, 'name' => $category->name])->values()->all();
        if ($blocked) {
            throw new BlogTaxonomyInUseException(__('blog.category_in_use'), $blocked);
        }

        BlogCategory::whereIn('id', $ids)->delete();
        return true;
    }

    public function getTags($partnerId = null)
    {
        $query = BlogTag::query();
        if ($partnerId) $query->where('partner_id', $partnerId);
        // Newest first: Existing Tags shows the latest at the top (BUG-059).
        $tags = $query->latest()->latest('id')->get();

        // A post holds its tags as a JSON list, which withCount() cannot match - count them here.
        $counts = $this->postsPerTag();
        $tags->each(fn ($tag) => $tag->setAttribute('blogs_count', $counts[$tag->id] ?? 0));

        return $tags;
    }

    /**
     * How many (not deleted) blog posts carry each tag.
     *
     * @return array<int, int> tag id => post count
     */
    private function postsPerTag(): array
    {
        $counts = [];
        foreach (Blog::whereNotNull('blog_tag_id')->pluck('blog_tag_id') as $tagIds) {
            foreach (array_unique(array_map('intval', (array) $tagIds)) as $tagId) {
                $counts[$tagId] = ($counts[$tagId] ?? 0) + 1;
            }
        }

        return $counts;
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

    /**
     * All or none: a tag still on a blog post is kept, and then nothing in this
     * call is deleted (BUG-059).
     *
     * @throws BlogTaxonomyInUseException
     */
    public function deleteTags(array $ids)
    {
        $counts = $this->postsPerTag();
        $blocked = BlogTag::whereIn('id', $ids)->get(['id', 'name'])
            ->filter(fn ($tag) => ($counts[$tag->id] ?? 0) > 0)
            ->map(fn ($tag) => ['id' => $tag->id, 'name' => $tag->name])->values()->all();
        if ($blocked) {
            throw new BlogTaxonomyInUseException(__('blog.tag_in_use'), $blocked);
        }

        BlogTag::whereIn('id', $ids)->delete();
        return true;
    }
}
