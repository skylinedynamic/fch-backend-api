<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\FCH\Common\Partner;
use App\Models\Funding\FundingSequencing;

class FundingReview extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_PENDING = 'Pending';
    const STATUS_APPROVED = 'Approved';
    const STATUS_REJECTED = 'Rejected';

    protected $fillable = [
        'partner_id',
        'borrower_id',
        'funding_sequence_id',
        'funding_type',
        'requested_amount',
        'use_of_funds',
        'documents_uploaded',
        'estimated_speed',
        'status',
        'approved_at',
        'approved_by',
        'reject_reason',
    ];

    protected $casts = [
        'documents_uploaded' => 'array',
        'approved_at' => 'datetime',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
    }

    public function fundingSequence()
    {
        return $this->belongsTo(FundingSequencing::class);
    }

    public function scopeSearch($query, $search)
    {
        if (!empty($search)) {
            $search = trim((string) $search);
            $query->where(function ($q) use ($search) {
                // Search borrower relations
                $q->whereHas('borrower', function ($qBorrower) use ($search) {
                    $qBorrower->where('first_name', 'like', "%{$search}%")
                              ->orWhere('last_name', 'like', "%{$search}%")
                              ->orWhere('business_name', 'like', "%{$search}%")
                              ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                })
                // Search funding sequence relations
                ->orWhereHas('fundingSequence', function ($qSequence) use ($search) {
                    $qSequence->where('name', 'like', "%{$search}%");
                })
                // Search own fields
                ->orWhere('funding_type', 'like', "%{$search}%")
                ->orWhere('use_of_funds', 'like', "%{$search}%")
                ->orWhere('requested_amount', 'like', "%{$search}%")
                ->orWhere('status', 'like', "%{$search}%");
            });
        }
        return $query;
    }
}
