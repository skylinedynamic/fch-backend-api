<?php

namespace App\Models\Setting;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Partner\Partner;

class Setting extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'partner_id',
        'card_name_sandbox',
        'card_no_sandbox',
        'card_expiry_sandbox',
        'card_cvv_sandbox',
        'card_name_production',
        'card_no_production',
        'card_expiry_production',
        'card_cvv_production',
        'currency',
        'status',
        'payment_mode',
        'stripe_key_sandbox',
        'stripe_secret_sandbox',
        'stripe_key_production',
        'stripe_secret_production',
        'payment_method',
        'configuration',
    ];

    protected $casts = [
        'configuration' => 'array',
        'partner_id' => 'integer',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }
}
