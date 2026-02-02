<?php

namespace App\Models\Commission;

use App\Models\Borrower\FundingApplication;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Commission extends Model
{
    use SoftDeletes;

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

    protected $fillable = [
        'funding_application_id',
        'total_commission',
        'admin_commission',
        'partner_commission',
        'total_commission_amount',
        'admin_commission_amount',
        'partner_commission_amount',
        'commission_status',
        'payment_status',
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
    ];

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
     * Get the user who last updated the commission record.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
