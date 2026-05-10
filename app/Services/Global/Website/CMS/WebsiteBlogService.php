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
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('title', 'like', '%' . $searchTerm . '%')
                  ->orWhere('content', 'like', '%' . $searchTerm . '%')
                  ->orWhere('summary', 'like', '%' . $searchTerm . '%')
                  ->orWhereHas('category', function($cq) use ($searchTerm) {
                      $cq->where('name', 'like', '%' . $searchTerm . '%');
                  })
                  ->orWhereHas('author', function($aq) use ($searchTerm) {
                      $aq->where('name', 'like', '%' . $searchTerm . '%');
                  });

                // Search by tag name
                $tagIds = BlogTag::where('name', 'like', '%' . $searchTerm . '%')->pluck('id')->toArray();
                if (!empty($tagIds)) {
                    $q->orWhere(function($tq) use ($tagIds) {
                        foreach ($tagIds as $id) {
                            $tq->orWhereJsonContains('blog_tag_id', (int)$id)
                               ->orWhereJsonContains('blog_tag_id', (string)$id);
                        }
                    });
                }
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
            ->where('is_featured', true)
            ->orderBy('published_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
