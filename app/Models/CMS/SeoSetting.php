<?php

namespace App\Models\CMS;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SeoSetting extends Model
{
    use SoftDeletes;
    
    protected $fillable = [
        'partner_id',
        'page_name',
        'path',
        'meta_title',
        'meta_description',
        'keywords',
        'og_image',
        'created_by',
        'updated_by',
    ];

    public function getOgImageAttribute($value)
    {
        if (!$value) return null;
        if (filter_var($value, FILTER_VALIDATE_URL)) return $value;
        $path = ltrim($value, '/');
        if (!str_starts_with($path, 'seo-settings/')) {
            $path = 'seo-settings/' . preg_replace('/^seo\//', '', $path);
        }
        return encodeUrl(asset('storage/' . $path));
    }

    public function partner()
    {
        return $this->belongsTo(\App\Models\User::class, 'partner_id');
    }
}
