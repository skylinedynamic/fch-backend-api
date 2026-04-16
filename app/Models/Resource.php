<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Resource extends Model
{
    use SoftDeletes;

    public const TYPE_LEGAL_REGISTRATION = 'legal_registration';
    public const TYPE_BUSINESS_SERVICES = 'business_services';
    public const TYPE_DOWNLOAD_ASSETS = 'download_assets';
    public const TYPE_EDUCATION_HELP = 'education_help';
    public const TYPE_TRAINING_ACADEMY = 'training_academy';

    public static function getTypes(): array
    {
        return [
            self::TYPE_LEGAL_REGISTRATION,
            self::TYPE_BUSINESS_SERVICES,
            self::TYPE_DOWNLOAD_ASSETS,
            self::TYPE_EDUCATION_HELP,
            self::TYPE_TRAINING_ACADEMY,
        ];
    }

    protected $fillable = [
        'resource_type',
        'title',
        'subtitle',
        'status',
        'file',
        'link',
        'icon',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    protected $appends = ['file_url'];

    public function getFileUrlAttribute()
    {
        if ($this->file && Storage::disk('public')->exists($this->file)) {
            return asset('storage/' . $this->file);
        }
        return null;
    }
}
