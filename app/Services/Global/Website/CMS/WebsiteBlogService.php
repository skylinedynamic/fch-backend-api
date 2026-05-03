<?php

namespace App\Services\Global\Website\CMS;

use App\Models\CMS\Blog;
use App\Models\CMS\BlogCategory;
use App\Models\CMS\BlogTag;
use Illuminate\Http\Request;

class WebsiteBlogService
{
    public function list(Request $request)
    {
        $query = Blog::published()->with(['category', 'author']);

        if ($request->category) {
            $query->whereHas('category', function($q) use ($request) {
                $q->where('slug', $request->category);
            });
        }

        if ($request->tag) {
            $tag = BlogTag::where('slug', $request->tag)->first();
            if ($tag) {
                $query->where(function($q) use ($tag) {
                    $q->whereJsonContains('blog_tag_id', (int)$tag->id)
                      ->orWhereJsonContains('blog_tag_id', (string)$tag->id);
                });
            }
        }

        if ($request->search) {
            $query->where(function($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('content', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->has('is_featured')) {
            $query->where('is_featured', $request->is_featured == '1' || $request->is_featured == 'true');
        }

        $perPage = $request->per_page ?? 9;
        return $query->orderBy('published_at', 'desc')->paginate($perPage);
    }

    public function findBySlug($slug)
    {
        $blog = Blog::published()
            ->with(['category', 'author'])
            ->where('slug', $slug)
            ->first();

        if (!$blog) {
            return null;
        }

        // Resolve tag IDs to names
        $tagIds = $blog->blog_tag_id;
        if (!is_array($tagIds)) {
            $tagIds = json_decode((string)$tagIds, true) ?? (is_numeric($tagIds) ? [(int)$tagIds] : []);
        }

        if (!empty($tagIds)) {
            $blog->tag_names = BlogTag::whereIn('id', (array)$tagIds)->pluck('name')->toArray();
        } else {
            $blog->tag_names = [];
        }

        return $blog;
    }

    public function getCategories()
    {
        return BlogCategory::withCount(['blogs' => function($q) {
            $q->published();
        }])->get();
    }

    public function getTags()
    {
        return BlogTag::all();
    }

    public function getLatest($limit = 3)
    {
        return Blog::published()
            ->orderBy('published_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
