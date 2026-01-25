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

    public function borrower(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Borrower\Borrower::class, 'borrower_id');
    }

    public function partner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Partner\Partner::class, 'partner_id');
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
            $q->where('invoices.invoice_id', 'like', "%{$search}%")
                ->orWhere('invoices.customer_name', 'like', "%{$search}%")
                ->orWhere('invoices.email', 'like', "%{$search}%")
                ->orWhere('invoices.cycle', 'like', "%{$search}%")
                ->orWhere('invoices.payment_amount', 'like', "%{$search}%")
                ->orWhere('invoices.payment_date', 'like', "%{$search}%")
                ->orWhere('invoices.created_at', 'like', "%{$search}%");

            // Accurate Status Matching: Use exact match if search matches a status to avoid "Paid" matching "Unpaid"
            $statuses = [
                'Paid',
                'Unpaid',
                'Disputed',
                'Failed',
                'Pending',
                'Success',
                'Canceled',
                'Overdue'
            ];

            if (in_array(ucfirst(strtolower($search)), $statuses)) {
                $q->orWhere('invoices.status', ucfirst(strtolower($search)));
            } else {
                $q->orWhere('invoices.status', 'like', "%{$search}%");
            }

            $q->orWhereHas('partner', function ($pq) use ($search) {
                $pq->where('company_name', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%");
            })->orWhereHas('borrower', function ($bq) use ($search) {
                $bq->where('business_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(borrowers.first_name, ' ', borrowers.last_name) LIKE ?", ["%{$search}%"]);
            });
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
