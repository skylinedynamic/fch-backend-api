<?php

namespace App\Models\Setting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class EmailConfiguration extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'partner_id',
        'key',
        'value',
    ];

    /**
     * Accurately handle encryption with fallback for legacy plain text data
     */
    public function getValueAttribute($value)
    {
        if (!$value) return $value;

        if (in_array($this->key, ['smtp_password', 'aws_secret_key'])) {
            try {
                return Crypt::decryptString($value);
            } catch (DecryptException $e) {
                return $value; // Return plain text if decryption fails (legacy data)
            }
        }

        return $value;
    }

    public function setValueAttribute($value)
    {
        if (in_array($this->key, ['smtp_password', 'aws_secret_key']) && $value) {
            $this->attributes['value'] = Crypt::encryptString($value);
        } else {
            $this->attributes['value'] = $value;
        }
    }

    public function partner()
    {
        return $this->belongsTo(\App\Models\Partner\Partner::class);
    }
}
