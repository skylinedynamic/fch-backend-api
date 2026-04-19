<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User\User;

class BorrowerChangeRequest extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'Pending';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_REJECTED = 'Rejected';

    protected $fillable = [
        'partner_id',
        'borrower_id',
        'field_name',
        'field_type',
        'visible_field_name',
        'db_field_name',
        'old_value',
        'new_value',
        'status',
        'updated_by',
        'note',
    ];

    /**
     * Get the borrower that owns the change request.
     */
    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
    }

    /**
     * Get the user who updated the change request.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
