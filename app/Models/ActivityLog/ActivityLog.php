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
    
    // New Log Types
    const LOG_TYPE_PARTNER_CREATE   = 'partner_create';
    const LOG_TYPE_PARTNER_UPDATE   = 'partner_update';
    const LOG_TYPE_EMAIL_SENT       = 'email_sent';
    const LOG_TYPE_ACTIVITY         = 'activity';
    const LOG_TYPE_BORROWER_CREATE  = 'borrower_create';
    const LOG_TYPE_BORROWER_UPDATE  = 'borrower_update';
    const LOG_TYPE_LEAD_CREATE      = 'lead_create';
    const LOG_TYPE_LEAD_UPDATE      = 'lead_update';
    const LOG_TYPE_BATCH_PROCESS    = 'batch_process';
    const LOG_TYPE_INVOICE_CREATE   = 'invoice_create';

    const STATUS_SENT     = 'sent';
    const STATUS_PENDING  = 'pending';
    const STATUS_FAILED   = 'failed';

    protected $fillable = [
        'log_type',
        'entity_type',
        'entity_id',
        'partner_id',
        'borrower_id',
        'properties',
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
            'properties'    => 'array',
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
            $q->where('properties->subject', 'like', "%{$search}%")
                ->orWhere('properties->summary', 'like', "%{$search}%")
                ->orWhere('properties->reference', 'like', "%{$search}%")
                ->orWhere('properties->to_emails', 'like', "%{$search}%")
                ->orWhere('properties->description', 'like', "%{$search}%");
        });
    }
}
