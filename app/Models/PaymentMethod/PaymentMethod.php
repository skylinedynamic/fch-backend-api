<?php

namespace App\Models\PaymentMethod;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Partner\Partner;
use Illuminate\Support\Facades\Crypt;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'partner_id',
        'key',
        'value'
    ];

    public const STRIPE = 'Stripe';
    public const ECRYPT = 'Ecrypt';
    public const SQUARE = 'Square';
    public const AUTHORIZE_NET = 'Authorize.Net';

    public const PAYMENT_METHOD_PROVIDERS = [
        self::STRIPE => self::STRIPE,
        self::ECRYPT => self::ECRYPT,
        self::SQUARE => self::SQUARE,
        self::AUTHORIZE_NET => self::AUTHORIZE_NET
    ];

    public const ENVIRONMENT_SANDBOX = 0;
    public const ENVIRONMENT_PRODUCTION = 1;

    public const MODE_SANDBOX = 'sandbox';
    public const MODE_PRODUCTION = 'production';

    /**
     * Keys that contain sensitive information and should be encrypted.
     */
    protected $encryptedKeys = [
        'stripe_secret_sandbox',
        'stripe_secret_production'
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * Accessor for the value attribute.
     * Decrypts sensitive information if necessary.
     */
    public function getValueAttribute($value)
    {
        if (in_array($this->key, $this->encryptedKeys) && !empty($value)) {
            try {
                return Crypt::decryptString($value);
            } catch (\Exception $e) {
                // If it fails to decrypt, it might not be encrypted yet (e.g. legacy data)
                return $value;
            }
        }
        return $value;
    }

    /**
     * Mutator for the value attribute.
     * Encrypts sensitive information before saving.
     */
    public function setValueAttribute($value)
    {
        if (in_array($this->key, $this->encryptedKeys) && !empty($value)) {
            $this->attributes['value'] = Crypt::encryptString($value);
        } else {
            $this->attributes['value'] = $value;
        }
    }
}
