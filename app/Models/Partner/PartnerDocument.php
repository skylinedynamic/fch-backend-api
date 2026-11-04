<?php

namespace App\Models\Partner;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Contracts\Activity;
use App\Support\Files\FileUrl;
use App\Support\Files\Folder;

class PartnerDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'partner_documents';

    protected $fillable = [
        'partner_id',
        'document_name',
        'file_path',
        'share_link',
        'size',
        // optional metadata (only persisted if DB has these columns)
    ];

    /**
     * Get the partner that owns the document.
     */
    /** Partner documents' own folder (BUG-016), apart from the partner's branding and pictures. */
    public const FOLDER = Folder::PARTNER_DOCUMENTS;

    /**
     * Where a document's file is on the uploads disk: its own path once moved
     * to partner-documents/, else the partner's folder it was uploaded to.
     */
    public static function storagePathFor(int|string $partnerId, string $filePath): string
    {
        return str_starts_with($filePath, self::FOLDER . '/') ? $filePath : Folder::PARTNERS . "/{$partnerId}/{$filePath}";
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    /**
     * Get the file path with storage prefix.
     */
    public function getFilePathAttribute($value)
    {
        return $value ? FileUrl::for(self::storagePathFor($this->partner_id, $value)) : null;
    }
}
