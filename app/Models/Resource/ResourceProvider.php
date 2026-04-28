<?php

namespace App\Models\Resource;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'name',
        'desc',
        'is_recommended',
        'btn_label',
        'link',
    ];

    protected $casts = [
        'is_recommended' => 'boolean',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
