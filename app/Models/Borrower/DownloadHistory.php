<?php

namespace App\Models\Borrower;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;

class DownloadHistory extends Model
{
    protected $fillable = [
        'user_id',
        'user_name',
        'user_type',
        'funding_application_id',
        'application_number',
        'application_version',
        'certificate_id',
        'file_type',
        'action',
        'sha256_hash',
        'ip_address',
        'downloaded_at',
        'details'
    ];

    protected $casts = [
        'details' => 'array',
        'downloaded_at' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function fundingApplication()
    {
        return $this->belongsTo(FundingApplication::class);
    }
}
