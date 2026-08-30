<?php

namespace App\Services\FCH\Admin\CMS;

use App\Models\CMS\SeoSetting;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Storage;

class SeoService
{
    public function list(array $filters)
    {
        $partnerId = $filters['partner_id'] ?? null;
        $query = SeoSetting::query();
        
        $sortField = $filters['sort_field'] ?? 'page_name';
        $sortOrder = $filters['sort_order'] ?? 'asc';
        $query->orderBy($sortField, $sortOrder);
        
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

        $perPage = $filters['per_page'] ?? 10;
        $settings = $query->paginate($perPage);

        return [
            'paginator' => $settings
        ];
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
                $path = $data['og_image']->store('seo-settings', 'public');
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
                    Storage::disk('public')->delete($setting->og_image);
                }
                $path = $data['og_image']->store('seo-settings', 'public');
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
