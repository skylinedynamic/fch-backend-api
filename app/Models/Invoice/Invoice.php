<?php

namespace App\Models\Invoice;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'partner_id',
        'customer_name',
        'email',
        'country',
        'payment_amount',
        'payment_date',
        'status',
    ];
}
