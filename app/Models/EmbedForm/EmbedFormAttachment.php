<?php

namespace App\Models\EmbedForm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class EmbedFormAttachment extends Model
{
    protected $table = 'embedded_form_attachments';

    public $timestamps = false;

    protected $guarded = [];

    protected $appends = ['file_url'];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(EmbedFormSubmission::class, 'submission_id');
    }

    public function getFileUrlAttribute(): ?string
    {
        if ($this->file_path) {
            return Storage::url($this->file_path);
        }
        return null;
    }

    public function getFileSizeFormattedAttribute(): string
    {
        $bytes = $this->file_size ?? 0;
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        return round($bytes / 1024, 2) . ' KB';
    }
}
