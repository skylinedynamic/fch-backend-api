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
        'downloaded_at' => 'datetime:Y-m-d h:i A'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function fundingApplication()
    {
        return $this->belongsTo(FundingApplication::class);
    }

    public function scopeSearch($query, $search)
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('user_name', 'like', '%' . $search . '%')
              ->orWhere('application_number', 'like', '%' . $search . '%')
              ->orWhere('certificate_id', 'like', '%' . $search . '%')
              ->orWhere('ip_address', 'like', '%' . $search . '%')
              ->orWhere('sha256_hash', 'like', '%' . $search . '%')
              ->orWhere('application_version', 'like', '%' . $search . '%')
              ->orWhere('action', 'like', '%' . $search . '%')
              ->orWhere('downloaded_at', 'like', '%' . $search . '%')
              ->orWhereRaw("DATE_FORMAT(downloaded_at, '%Y-%m-%d %h:%i %p') LIKE ?", ['%' . $search . '%']);
        });
    }
}
