<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'invoice_id',
        'partner_id',
        'entity_type', // Client, Borrower, etc.
        'entity_id',
        'customer_name',
        'email',
        'country',
        'payment_amount',
        'payment_date',
        'status',
        'cycle',
        'created_by',
        'updated_by',
    ];

    const STATUS_PAID = 'Paid';
    const STATUS_UNPAID = 'Unpaid';
    const STATUS_DISPUTED = 'Disputed';
    const STATUS_FAILED = 'Failed';
    const STATUS_PENDING = 'Pending';
    const STATUS_SUCCESS = 'Success';
    const STATUS_CANCELED = 'Canceled';

    const CYCLE_ANNUAL = 'Annual';
    const CYCLE_MONTHLY = 'Monthly';
    const CYCLE_QUARTERLY = 'Quarterly';
    const CYCLE_ONE_TIME = 'One Time';

    // Helper to get status colors/badge classes if needed
    public static function getStatusColor($status)
    {
        return match ($status) {
            self::STATUS_PAID, self::STATUS_SUCCESS => 'success',
            self::STATUS_UNPAID, self::STATUS_FAILED, self::STATUS_CANCELED => 'danger',
            self::STATUS_PENDING => 'warning',
            self::STATUS_DISPUTED => 'info',
            default => 'secondary',
        };
    }
}
