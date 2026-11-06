<?php

namespace App\Models\Resource;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\Files\FileUrl;
use App\Support\Files\StoragePath;
use App\Support\Files\StoredFileName;
use App\Support\Files\UploadsFilesystem;
use Illuminate\Support\Facades\Storage;
use App\Support\Files\Disk;
use App\Support\Files\Folder;

class ResourceProvider extends Model
{
    use HasFactory;

    /**
     * Providers' logos (BUG-016): public, shown on every Resources page.
     * resources/ itself is private, so a logo picked from it is kept here.
     */
    public const LOGO_FOLDER = Folder::RESOURCE_PROVIDERS;

    /**
     * The path a provider's logo is kept under: as given when it is already
     * public; a public copy in resource-providers/ when it was picked from a
     * private folder (resources/). Nothing, or a file not there, as given.
     */
    public static function publicLogoPath(?string $logo): ?string
    {
        $path = StoragePath::fromUrl($logo);
        /** @var UploadsFilesystem $uploads */
        $uploads = Storage::disk(Disk::UPLOADS);

        if (!$path || !$uploads->isPrivate($path) || !$uploads->exists($path)) {
            return $logo;
        }

        $copy = self::LOGO_FOLDER . '/' . StoredFileName::make(basename($path));
        $uploads->copy($path, $copy);

        return $copy;
    }

    protected $fillable = [
        'resource_id',
        'name',
        'desc',
        'desc_list',
        'logo',
        'is_recommended',
        'btn_label',
        'link',
        'sort_order',
    ];

    protected $casts = [
        'is_recommended' => 'boolean',
        'desc_list' => 'array',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function getFullLogoUrlAttribute()
    {
        return $this->logo ? FileUrl::for($this->logo) : null;
    }
}
