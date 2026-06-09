<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerCreditProfile extends Model
{
    use HasFactory;

    protected $table = 'borrower_credit_profiles';

    protected $guarded = [];

    protected $casts = [
        'fico_score' => 'integer',
        'paydex_score' => 'integer',
        'experian_intelliscore' => 'integer',
        'equifax_score' => 'integer',
        'active_tradelines' => 'integer',
        'total_credit_limit' => 'float',
        'credit_utilization' => 'float',
        'recent_inquiries' => 'integer',
        'delinquent_accounts' => 'integer',
        'public_records' => 'integer',
        'collections' => 'integer',
        'all_accounts_total' => 'integer',
        'all_accounts_open' => 'integer',
        'all_accounts_closed' => 'integer',
        'open_accounts_monthly_payments' => 'float',
        'open_accounts_balance' => 'float',
        'open_accounts_credit_limit' => 'float',
        'open_accounts_available_credit' => 'float',
        'open_accounts_utilization_ratio' => 'float',
        'all_accounts_analysis' => 'array',
        'open_accounts_analysis' => 'array',
        'personal_info' => 'array',
    ];

    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
    }

    public function addresses()
    {
        return $this->hasMany(BorrowerCreditAddress::class, 'borrower_credit_profile_id');
    }

    public function trades()
    {
        return $this->hasMany(BorrowerCreditTrade::class, 'borrower_credit_profile_id');
    }

    public function inquiries()
    {
        return $this->hasMany(BorrowerCreditInquiry::class, 'borrower_credit_profile_id');
    }

    public function document()
    {
        return $this->belongsTo(BorrowerDocument::class, 'document_id');
    }
}
