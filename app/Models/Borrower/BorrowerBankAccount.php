<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerBankAccount extends Model
{
    use HasFactory;

    protected $table = 'borrower_bank_accounts';

    protected $fillable = [
        'borrower_id',
        'bank_name',
        'account_open_date',
    ];

    protected $casts = [
        'account_open_date' => 'date:Y-m-d',
    ];

    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
    }
}
