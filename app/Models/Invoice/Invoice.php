<?php

namespace App\Models\Invoice;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'invoice_id',
        'partner_id',
        'borrower_id',
        'entity_type', // Client, Borrower, etc.
        'entity_id',
        'customer_name',
        'email',
        'country',
        'payment_amount',
        'payment_date',
        'status',
        'cycle',
        'details',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'details' => 'array',
        'payment_date' => 'date',
    ];

    public function borrower()
    {
        return $this->belongsTo(\App\Models\Borrower\Borrower::class);
    }

    /**
     * Scope: apply text search across common invoice fields.
     */
    public function scopeSearch($query, ?string $search)
    {
        if (!is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('invoice_id', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('status', 'like', "%{$search}%")
                ->orWhere('cycle', 'like', "%{$search}%")
                ->orWhere('payment_amount', 'like', "%{$search}%")
                ->orWhere('payment_date', 'like', "%{$search}%")
                ->orWhere('created_at', 'like', "%{$search}%");
        });
    }

    const STATUS_PAID = 'Paid';
    const STATUS_UNPAID = 'Unpaid';
    const STATUS_DISPUTED = 'Disputed';
    const STATUS_FAILED = 'Failed';
    const STATUS_PENDING = 'Pending';
    const STATUS_SUCCESS = 'Success';
    const STATUS_CANCELED = 'Canceled';
    const STATUS_OVERDUE = 'Overdue';
    const STATUS_ALL = 'All Status';

    const CYCLE_ANNUAL = 'Annual';
    const CYCLE_MONTHLY = 'Monthly';
    const CYCLE_QUARTERLY = 'Quarterly';
    const CYCLE_ONE_TIME = 'One Time';
    const CYCLE_ALL = 'All Cycle';

    const PAYEE_ALL = 'All Payees';
}
