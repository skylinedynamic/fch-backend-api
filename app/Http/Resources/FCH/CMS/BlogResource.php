<?php

namespace App\Http\Resources\FCH\CMS;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'blog_tag_id' => $this->blog_tag_id,
            'summary' => $this->summary,
            'content' => $this->content,
            'featured_image' => $this->featured_image,
            'meta_title' => $this->meta_title ?? $this->title,
            'meta_description' => $this->meta_description ?? $this->summary,
            'keywords' => $this->keywords,
            'is_published' => (bool)$this->is_published,
            'is_featured' => (bool)$this->is_featured,
            'published_at' => $this->published_at,
            'author_id' => $this->author_id,
            'author' => $this->whenLoaded('author'),
            'tags' => \App\Models\CMS\BlogTag::whereIn('id', is_array($this->blog_tag_id) ? $this->blog_tag_id : [])->get()->map(fn($t) => ['id' => $t->id, 'name' => $t->name]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
