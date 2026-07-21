<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavedPaymentMethod extends Model
{
    use HasFactory;

    protected $table = 'saved_payment_methods';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'provider_method_id',
        'provider',
        'card_brand',
        'card_last_four',
        'exp_month',
        'exp_year',
        'is_default',
    ];

    /**
     * Get the owning billable entity (Partner or Borrower).
     */
    public function entity()
    {
        return $this->morphTo();
    }
}
