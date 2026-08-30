<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerCreditTrade extends Model
{
    use HasFactory;

    protected $table = 'borrower_credit_trades';

    protected $guarded = [];

    protected $casts = [
        'balance' => 'float',
        'high_credit' => 'float',
        'limit' => 'float',
        'monthly_payment' => 'float',
        'actual_payment' => 'float',
        'past_due' => 'float',
        'months_reviewed' => 'integer',
    ];

    public function profile()
    {
        return $this->belongsTo(BorrowerCreditProfile::class, 'borrower_credit_profile_id');
    }
}
