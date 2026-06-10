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
}
