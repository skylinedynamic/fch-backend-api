<?php

namespace App\Models\CreditBuilder;

use App\Models\Borrower\Borrower;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditAccountApplication extends Model
{
    protected $guarded = ['id'];

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(CreditAccount::class);
    }
}
