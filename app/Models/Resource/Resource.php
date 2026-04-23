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
        'is_partner',
        'is_borrower',
    ];

    protected $casts = [
        'status' => 'boolean',
        'is_partner' => 'boolean',
        'is_borrower' => 'boolean',
    ];

    protected $appends = ['file_url'];

    /**
     * Resolves the actual file path on the disk by checking multiple common locations.
     */
    public function resolveFilePath(): ?string
    {
        $path = ltrim($this->file ?? '', '/');
        if (!$path) return null;

        $disk = Storage::disk('public');
        
        // 1. Check raw path
        if ($disk->exists($path)) return $path;

        // 2. Check in 'resources/' folder (standard upload location)
        $resourcePath = "resources/" . $path;
        if ($disk->exists($resourcePath)) return $resourcePath;

        // 3. Check ID-prefixed path (alternative structure)
        $idPrefixedPath = "resources/{$this->id}/" . $path;
        if ($disk->exists($idPrefixedPath)) return $idPrefixedPath;

        return null;
    }

    public function getFileUrlAttribute()
    {
        $resolvedPath = $this->resolveFilePath();
        return $resolvedPath ? asset('storage/' . encodeUrl($resolvedPath)) : null;
    }

    /**
     * Download the associated file
     */
    public function download()
    {
        $resolvedPath = $this->resolveFilePath();
        
        if (!$resolvedPath) return null;

        $disk = Storage::disk('public');
        $absolutePath = $disk->path($resolvedPath);

        if (!file_exists($absolutePath)) return false;

        $extension = pathinfo($absolutePath, PATHINFO_EXTENSION);
        $documentName = $this->title;

        if ($extension && !str_ends_with(strtolower($documentName), '.' . strtolower($extension))) {
            $documentName .= '.' . $extension;
        }

        return response()->download($absolutePath, $documentName, [
            'Content-Type' => mime_content_type($absolutePath),
            'Content-Disposition' => 'attachment; filename="' . $documentName . '"',
        ]);
    }
}

