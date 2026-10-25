<?php

namespace App\Models\CMS;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Blog extends Model
{
    use SoftDeletes, HasSlug;

    public const STATUS_PUBLISHED = 1;
    public const STATUS_DRAFT = 0;
    public const STATUS_PUBLISHED_LABEL = 'published';
    public const STATUS_DRAFT_LABEL = 'draft';

    protected $fillable = [
        'partner_id',
        'title',
        'slug',
        'category_id',
        'blog_tag_id',
        'content',
        'summary',
        'featured_image',
        'og_image',
        'meta_title',
        'meta_description',
        'keywords',
        'is_published',
        'is_featured',
        'published_at',
        'author_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'is_featured'  => 'boolean',
        'published_at' => 'datetime',
        'blog_tag_id'  => 'array',
    ];

    public function getFeaturedImageAttribute($value)
    {
        if (!$value) return null;
        if (filter_var($value, FILTER_VALIDATE_URL)) return $value;
        $path = ltrim($value, '/');
        if (!str_starts_with($path, 'blogs/')) {
            $path = 'blogs/' . $path;
        }
        return encodeUrl(asset('storage/' . $path));
    }

    public function getOgImageAttribute($value)
    {
        if (!$value) return null;
        if (filter_var($value, FILTER_VALIDATE_URL)) return $value;
        $path = ltrim($value, '/');
        if (!str_starts_with($path, 'blogs/')) {
            $path = 'blogs/' . $path;
        }
        return encodeUrl(asset('storage/' . $path));
    }

    /**
     * The post's web address, from its title: unique within the partner (as
     * the blogs (slug, partner_id) index, deleted posts included), so a
     * repeated title becomes "title-2", "title-3" (BUG-045). Set once - editing
     * the post keeps its address.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('title')
            ->saveSlugsTo('slug')
            ->startSlugSuffixFrom(2)
            ->extraScope(fn ($query) => $query->where('partner_id', $this->partner_id))
            ->doNotGenerateSlugsOnUpdate();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopeFilter($query, array $filters)
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%")
                  ->orWhere('summary', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%")
                  ->orWhere('created_at', 'like', "%{$search}%")
                  ->orWhereRaw("DATE_FORMAT(created_at, '%b %d, %Y') like ?", ["%{$search}%"])
                  ->orWhereRaw("DATE_FORMAT(created_at, '%M %d, %Y') like ?", ["%{$search}%"])
                  ->orWhereHas('category', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%");
                  });

                // Search by tag name in JSON column
                $tagIds = \App\Models\CMS\BlogTag::where('name', 'like', "%{$search}%")->pluck('id')->toArray();
                if (!empty($tagIds)) {
                    $q->orWhere(function($tagQuery) use ($tagIds) {
                        foreach ($tagIds as $id) {
                            $tagQuery->orWhereJsonContains('blog_tag_id', (int)$id)
                                     ->orWhereJsonContains('blog_tag_id', (string)$id);
                        }
                    });
                }
                // Accurate Status Searching (Support words and numbers)
                $searchLower = strtolower($search);
                if (str_contains(self::STATUS_PUBLISHED_LABEL, $searchLower) || $searchLower === (string)self::STATUS_PUBLISHED) {
                    $q->orWhere('is_published', true);
                }
                if (str_contains(self::STATUS_DRAFT_LABEL, $searchLower) || $searchLower === (string)self::STATUS_DRAFT) {
                    $q->orWhere('is_published', false);
                }
            });
        });

        $query->when($filters['category_id'] ?? null, function ($query, $categoryId) {
            $query->where('category_id', $categoryId);
        });

        $query->when(isset($filters['status']) && $filters['status'] !== '', function ($query) use ($filters) {
            $status = $filters['status'];
            // If status is passed as an object/array from PrimeReact {label, value}
            if (is_array($status) && isset($status['value'])) {
                $status = $status['value'];
            }
            
            if ($status === '') return;

            $statusStr = (string)$status;
            if ($statusStr === (string)self::STATUS_PUBLISHED || $statusStr === self::STATUS_PUBLISHED_LABEL) {
                $query->where('is_published', true);
            } elseif ($statusStr === (string)self::STATUS_DRAFT || $statusStr === self::STATUS_DRAFT_LABEL) {
                $query->where('is_published', false);
            }
        });

        $query->when(isset($filters['is_featured']) && $filters['is_featured'] !== '', function ($query) use ($filters) {
            $query->where('is_featured', (bool)$filters['is_featured']);
        });

        return $query;
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true)
            ->where('published_at', '<=', now());
    }
}
