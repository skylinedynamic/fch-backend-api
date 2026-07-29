<?php

namespace App\Models\PaymentMethod;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentProvider extends Model
{
    use HasFactory;

    protected $table = 'payment_providers';

    protected $fillable = [
        'name',
        'logo',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public const STRIPE = 'Stripe';
    public const ECRYPT = 'Ecrypt';
    public const SQUARE = 'Square';
    public const AUTHORIZE_NET = 'Authorize.Net';
    public const CASH = 'Cash Payment';
    public const BANK_TRANSFER = 'Bank Transfer';


    public const MODE_SANDBOX = 'sandbox';
    public const MODE_PRODUCTION = 'production';

    public function partnerPaymentProviders()
    {
        return $this->hasMany(PartnerPaymentProvider::class, 'payment_provider_id');
    }
}
