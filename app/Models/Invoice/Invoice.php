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
        'created_by',
        'updated_by',
    ];

    public function borrower()
    {
        return $this->belongsTo(\App\Models\Borrower\Borrower::class);
    }

    const STATUS_PAID = 'Paid';
    const STATUS_UNPAID = 'Unpaid';
    const STATUS_DISPUTED = 'Disputed';
    const STATUS_FAILED = 'Failed';
    const STATUS_PENDING = 'Pending';
    const STATUS_SUCCESS = 'Success';
    const STATUS_CANCELED = 'Canceled';
    const STATUS_ALL = 'All Status';

    const CYCLE_ANNUAL = 'Annual';
    const CYCLE_MONTHLY = 'Monthly';
    const CYCLE_QUARTERLY = 'Quarterly';
    const CYCLE_ONE_TIME = 'One Time';
    const CYCLE_ALL = 'All Cycle';

    const PAYEE_ALL = 'All Payees';
}
