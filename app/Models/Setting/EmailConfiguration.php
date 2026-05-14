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
        'enable_custom_configuration',
        'site_name',
        'email_provider',
        'smtp_driver',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'aws_access_key',
        'aws_secret_key',
        'aws_region',
        'from_address',
        'from_name',
        'bcc_emails',
        'is_active',
    ];

    protected $casts = [
        'enable_custom_configuration' => 'boolean',
        'is_active'                   => 'boolean',
    ];

    /**
     * Accurately handle encryption with fallback for legacy plain text data
     */
    public function getSmtpPasswordAttribute($value)
    {
        if (!$value) return $value;
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            return $value; // Return plain text if decryption fails (legacy data)
        }
    }

    public function setSmtpPasswordAttribute($value)
    {
        $this->attributes['smtp_password'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getAwsSecretKeyAttribute($value)
    {
        if (!$value) return $value;
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            return $value;
        }
    }

    public function setAwsSecretKeyAttribute($value)
    {
        $this->attributes['aws_secret_key'] = $value ? Crypt::encryptString($value) : null;
    }

    public function partner()
    {
        return $this->belongsTo(\App\Models\Partner\Partner::class);
    }
}
