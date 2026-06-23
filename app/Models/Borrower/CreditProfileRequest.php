<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditProfileRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'credit_profile_requests';

    protected $guarded = [];

    protected $casts = [
        'date_of_birth' => 'date',
    ];

    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
    }
}
