<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerCreditInquiry extends Model
{
    use HasFactory;

    protected $table = 'borrower_credit_inquiries';

    protected $guarded = [];

    public function profile()
    {
        return $this->belongsTo(BorrowerCreditProfile::class, 'borrower_credit_profile_id');
    }
}
