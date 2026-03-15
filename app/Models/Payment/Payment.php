<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\User\User;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'payments';

    protected $guarded = [];

    protected $casts = [
        'transaction_date' => 'datetime',
        'details' => 'array',
    ];

    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PENDING = 'pending';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_REFUNDED = 'refunded';

    public function partner()
    {
        return $this->belongsTo(Partner::class, 'partner_id', 'id');
    }

    public function borrower()
    {
        return $this->belongsTo(Borrower::class, 'borrower_id', 'id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoice()
    {
        return $this->belongsTo(\App\Models\Invoice\Invoice::class, 'invoice_id');
    }

    /**
     * Scope: apply text search across common payment fields.
     */
    public function scopeSearch($query, ?string $search)
    {
        if (!is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('payments.transaction_id', 'like', "%{$search}%")
                ->orWhere('payments.payment_from', 'like', "%{$search}%")
                ->orWhere('payments.payment_type', 'like', "%{$search}%")
                ->orWhere('payments.amount', 'like', "%{$search}%")
                ->orWhere('payments.transaction_date', 'like', "%{$search}%");

            // Accurate Status Matching: Prevent "Paid" from matching "Unpaid"
            $statuses = ['Paid', 'Unpaid', 'Success', 'Failed', 'Pending', 'Succeeded'];
            if (in_array(ucfirst(strtolower($search)), $statuses)) {
                $q->orWhere('payments.status', strtolower($search));
            } else {
                $q->orWhere('payments.status', 'like', "%{$search}%");
            }

            $q->orWhereHas('partner', function ($pq) use ($search) {
                $pq->where('company_name', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%");
            })
                ->orWhereHas('borrower', function ($bq) use ($search) {
                    $bq->where('business_name', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhereRaw("CONCAT(borrowers.first_name, ' ', borrowers.last_name) LIKE ?", ["%{$search}%"]);
                })
                ->orWhereHas('invoice', function ($iq) use ($search) {
                    $iq->where('invoice_id', 'like', "%{$search}%");
                });
        });
    }
}
