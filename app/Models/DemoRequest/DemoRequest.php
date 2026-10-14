<?php

namespace App\Models\DemoRequest;

use App\Models\User\User;
use App\Models\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DemoRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'full_name',
        'customer_name',
        'phone',
        'email',
        'state',
        'country',
        'zip_code',
        'joining_date',
        'tax_id',
        'website',
        'assigned_to',
        'assigned_email',
        'assigned_phone',
        'demo_start',
        'demo_end',
        'status',
        'duration_days',
        'created_by',
    ];

    protected $dates = [
        'demo_start',
        'demo_end',
    ];

    const PENDING = 'pending';
    const COMPLETED = 'completed';
    const CANCELLED = 'cancelled';

    public const STATUS = [
        self::PENDING => self::PENDING,
        self::COMPLETED => self::COMPLETED,
        self::CANCELLED => self::CANCELLED,
    ];

    // Relationships (if needed)
    public function assignedUser()
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
