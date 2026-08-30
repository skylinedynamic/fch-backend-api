<?php

namespace App\Models\PaymentMethod;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Partner\Partner;

class PaymentMethodBankDetail extends Model
{
    use HasFactory;

    protected $table = 'payment_method_bank_details';

    protected $fillable = [
        'partner_id',
        'bank_name',
        'account_name',
        'account_number',
        'routing_number',
        'instructions',
        'payment_mode',
        'is_default',
        'status',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'status' => 'boolean',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }
}
