<?php

namespace App\Models\Resource;

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
        $path = ltrim($this->file ?? '', '/');
        if ($path && Storage::disk('public')->exists($path)) {
            return asset('storage/' . encodeUrl($path));
        }
        return null;
    }

    /**
     * Download the associated file
     */
    public function download()
    {
        $path = ltrim($this->file ?? '', '/');
        if (!$path) {
            return null;
        }

        if (!Storage::disk('public')->exists($path)) {
            return false;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $fileName = $this->title . ($extension ? '.' . $extension : '');

        return Storage::disk('public')->download($path, $fileName);
    }
}
