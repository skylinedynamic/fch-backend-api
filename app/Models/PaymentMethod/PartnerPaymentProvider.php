<?php

namespace App\Models\PaymentMethod;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Partner\Partner;

class PartnerPaymentProvider extends Model
{
    use HasFactory;

    protected $table = 'partner_payment_providers';

    protected $fillable = [
        'partner_id',
        'payment_provider_id',
        'status',
        'is_default',
        'payment_mode',
        'configuration',
    ];

    protected $casts = [
        'status' => 'boolean',
        'is_default' => 'boolean',
        'configuration' => 'encrypted:array',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function provider()
    {
        return $this->belongsTo(PaymentProvider::class, 'payment_provider_id');
    }
}
