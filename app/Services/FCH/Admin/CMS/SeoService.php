<?php

namespace App\Services\FCH\Admin\CMS;

use App\Models\CMS\SeoSetting;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Storage;
use App\Support\Files\Disk;
use App\Support\Files\Folder;

class SeoService
{
    /** Columns the list may sort or filter on; anything else is ignored, never put into SQL. */
    private const SORTABLE = ['id', 'page_name', 'path', 'meta_title', 'created_at', 'updated_at'];
    private const FILTERABLE = ['page_name', 'path', 'meta_title'];

    public function list(array $filters)
    {
        $partnerId = $filters['partner_id'] ?? null;
        $query = SeoSetting::query();

        $sortField = in_array($filters['sort_field'] ?? null, self::SORTABLE, true) ? $filters['sort_field'] : 'id';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortField, $sortOrder);

        // The table's column filters (BUG-124): filters[field] = {value, match_mode}.
        foreach ((array) ($filters['columns'] ?? []) as $field => $filter) {
            $this->applyColumnFilter($query, $field, $filter);
        }
        
        if ($partnerId) {
            $query->where('partner_id', $partnerId);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('page_name', 'like', "%{$search}%")
                  ->orWhere('path', 'like', "%{$search}%")
                  ->orWhere('meta_title', 'like', "%{$search}%");
            });
        }

        $page = $filters['page'] ?? 1;
        $perPage = $filters['per_page'] ?? 10;
        $settings = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'paginator' => $settings
        ];
    }

    /**
     * One column filter of the SEO table, with the table's match modes. An empty
     * value, an unknown column or an unknown mode filters nothing.
     */
    private function applyColumnFilter($query, $field, $filter): void
    {
        $value = is_array($filter) ? trim((string) ($filter['value'] ?? '')) : '';
        if ($value === '' || !in_array($field, self::FILTERABLE, true)) {
            return;
        }

        $like = addcslashes($value, '%_\\');
        match ($filter['match_mode'] ?? 'startsWith') {
            'startsWith'  => $query->where($field, 'like', "{$like}%"),
            'contains'    => $query->where($field, 'like', "%{$like}%"),
            'notContains' => $query->where(fn ($q) => $q->where($field, 'not like', "%{$like}%")->orWhereNull($field)),
            'endsWith'    => $query->where($field, 'like', "%{$like}"),
            'equals'      => $query->where($field, $value),
            'notEquals'   => $query->where(fn ($q) => $q->where($field, '!=', $value)->orWhereNull($field)),
            default       => null,
        };
    }

    public function getAll($partnerId = null)
    {
        $query = SeoSetting::orderBy('page_name', 'asc');
        if ($partnerId) $query->where('partner_id', $partnerId);
        return $query->get();
    }

    public function findById($id)
    {
        return SeoSetting::find($id);
    }

    public function store(array $data)
    {
        if (isset($data['og_image'])) {
            if ($data['og_image'] instanceof \Illuminate\Http\UploadedFile) {
                $path = $data['og_image']->store(Folder::SEO_SETTINGS, Disk::UPLOADS);
                $data['og_image'] = $path;
            } elseif (is_string($data['og_image'])) {
                $data['og_image'] = $this->normalizePath($data['og_image']);
            }
        }

        return SeoSetting::create($data);
    }

    public function update(SeoSetting $setting, array $data)
    {
        if (isset($data['og_image'])) {
            if ($data['og_image'] instanceof \Illuminate\Http\UploadedFile) {
                if ($setting->og_image && !str_contains($setting->og_image, 'http')) {
                    Storage::disk(Disk::UPLOADS)->delete($setting->og_image);
                }
                $path = $data['og_image']->store(Folder::SEO_SETTINGS, Disk::UPLOADS);
                $data['og_image'] = $path;
            } elseif (is_string($data['og_image'])) {
                $data['og_image'] = $this->normalizePath($data['og_image']);
            }
        }

        $setting->update($data);
        return $setting;
    }

    public function delete(array $ids)
    {
        SeoSetting::whereIn('id', $ids)->delete();
        return ApiResponse::success(null, 'SEO settings deleted successfully');
    }

    public function getByPath($path)
    {
        return SeoSetting::where('path', $path)->first();
    }

    /**
     * Normalize file manager path.
     */
    private function normalizePath(string $path): string
    {
        $path = ltrim($path, '/');
        // If it's a full URL, we don't normalize it
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        
        // Remove storage/ prefix if it exists (for compatibility)
        $path = preg_replace('/^storage\//', '', $path);
        
        if (!str_starts_with($path, 'seo-settings/')) {
            return 'seo-settings/' . preg_replace('/^seo\//', '', $path);
        }
        return $path;
    }
}
