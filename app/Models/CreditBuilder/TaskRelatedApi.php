<?php

namespace App\Models\CreditBuilder;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskRelatedApi extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'base_url',
        'client_id',
        'client_secret',
        'additional_info',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $hidden = [
        'client_secret',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
