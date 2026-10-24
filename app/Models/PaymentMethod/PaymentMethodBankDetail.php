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

    /**
     * The accounts a payer can be shown for a partner in one mode: switched on,
     * and either saved for that mode or for none (rows from before modes).
     */
    public function scopeAvailableFor($query, ?int $partnerId, string $mode)
    {
        return $query->where('partner_id', $partnerId)
            ->where(function ($q) use ($mode) {
                $q->where('payment_mode', $mode)
                    ->orWhereNull('payment_mode');
            })
            ->where('status', true);
    }
}
