<?php

namespace App\Models\Commission;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Commission extends Model
{
    use SoftDeletes;
    
    protected $fillable = [
        'borrower_id',
        'partner_id',
        'funding_application_id',
        'total_commission',
        'admin_commission',
        'partner_commission',
        'total_commission_amount',
        'admin_commission_amount',
        'partner_commission_amount',
        'commission_status',
        'payment_status',
        'payout_date',
        'currency',
        'currency_symbol',
        'conversion_rate',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'total_commission' => 'decimal:2',
        'admin_commission' => 'decimal:2',
        'partner_commission' => 'decimal:2',
        'total_commission_amount' => 'decimal:2',
        'admin_commission_amount' => 'decimal:2',
        'partner_commission_amount' => 'decimal:2',
        'conversion_rate' => 'decimal:2',
        'payout_date' => 'date',
    ];

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_PENDING = 'Pending';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_REJECTED = 'Rejected';

    public const STATUS = [
        self::STATUS_DRAFT => self::STATUS_DRAFT,
        self::STATUS_PENDING => self::STATUS_PENDING,
        self::STATUS_APPROVED => self::STATUS_APPROVED,
        self::STATUS_REJECTED => self::STATUS_REJECTED,
    ];

    public const PAYMENT_STATUS_PENDING = 'Pending';
    public const PAYMENT_STATUS_PAID = 'Paid';
    public const PAYMENT_STATUS_UNPAID = 'Unpaid';

    public const PAYMENT_STATUS = [
        self::PAYMENT_STATUS_PENDING => self::PAYMENT_STATUS_PENDING,
        self::PAYMENT_STATUS_PAID => self::PAYMENT_STATUS_PAID,
        self::PAYMENT_STATUS_UNPAID => self::PAYMENT_STATUS_UNPAID,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (Auth::check()) {
                $model->created_by = Auth::id();
                $model->updated_by = Auth::id();
            }
        });

        static::updating(function ($model) {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });
    }


    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * Get the funding application that owns the commission.
     */
    public function fundingApplication()
    {
        return $this->belongsTo(FundingApplication::class);
    }

    /**
     * Get the user who created the commission record.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope a query to search for terms.
     */
    public function scopeSearch($query, string $term)
    {
        $like = "%{$term}%";

        return $query->where(function ($q) use ($like) {
            $q->where('total_commission_amount', 'like', $like)
              ->orWhereHas('borrower', function ($q) use ($like) {
                  $q->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like);
              })
              ->orWhereHas('partner', function ($q) use ($like) {
                  $q->where('full_name', 'like', $like)
                    ->orWhere('company_name', 'like', $like);
              })
              ->orWhereHas('fundingApplication', function ($q) use ($like) {
                  $q->where('business_name', 'like', $like)
                    ->orWhere('full_name', 'like', $like);
              });
        });
    }

    /**
     * Get the user who last updated the commission record.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
