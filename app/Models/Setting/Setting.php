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

        'payment_mode',
        'stripe_key_sandbox',
        'stripe_secret_sandbox',
        'stripe_key_production',
        'stripe_secret_production',
        'payment_method',
        'configuration',
        'non_host_enrollment_price',
    ];

    protected $casts = [
        'configuration' => 'array',
        'partner_id' => 'integer',
        'non_host_enrollment_price' => 'decimal:2',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }
}
