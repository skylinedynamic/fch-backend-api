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

    protected $guarded = [];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
