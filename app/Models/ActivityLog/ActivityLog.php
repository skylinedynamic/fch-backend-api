<?php

namespace App\Models\ActivityLog;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ActivityLog extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'activity_log';

    const TYPE_EMAIL    = 'email';
    const TYPE_ACTIVITY = 'activity';

    const STATUS_SENT     = 'sent';
    const STATUS_PENDING  = 'pending';
    const STATUS_FAILED   = 'failed';

    protected $fillable = [
        'partner_id',
        'borrower_id',
        'log_name',
        'description',
        'properties',
        'reference',
        'category',
        'activity_date',
        'assigned_to',
        'to_emails',
        'subject',
        'attachments',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'to_emails'     => 'array',
            'attachments'   => 'array',
            'properties'    => 'array',
            'activity_date' => 'date',
            'created_at'    => 'datetime',
            'updated_at'    => 'datetime',
            'deleted_at'    => 'datetime',
        ];
    }

    public function partner()
    {
        return $this->belongsTo(\App\Models\Partner\Partner::class, 'partner_id');
    }

    public function borrower()
    {
        return $this->belongsTo(\App\Models\Borrower\Borrower::class, 'borrower_id');
    }


    public function assignedUser()
    {
        return $this->belongsTo(\App\Models\User\User::class, 'assigned_to');
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User\User::class, 'created_by');
    }

    public function scopeSearch($query, ?string $search)
    {
        if (!is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('subject', 'like', "%{$search}%")
                ->orWhere('summary', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('to_emails', 'like', "%{$search}%")
                ->orWhere('message_body', 'like', "%{$search}%");
        });
    }
}
