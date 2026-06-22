<?php

namespace App\Models\FundingReview;

use App\Models\Borrower\Borrower;
use App\Models\Funding\FundingSequencing;
use App\Models\Lender\Lender;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'lender_ids',
    ];

    protected $casts = [
        'documents_uploaded' => 'array',
        'is_approved' => 'boolean',
        'approved_at' => 'datetime',
        'lender_ids' => 'array',
    ];

    protected $appends = ['documents_with_urls', 'matched_lenders', 'lender_names'];

    public function getDocumentsWithUrlsAttribute()
    {
        $docs = [];
        $uploaded = $this->documents_uploaded ?? [];
        if (!is_array($uploaded)) {
            return $docs;
        }

        if ($this->relationLoaded('borrower') && $this->borrower && $this->borrower->relationLoaded('borrowerDocuments')) {
            $borrowerDocs = $this->borrower->borrowerDocuments;
            foreach ($uploaded as $docName) {
                $docNameString = str_replace('_', ' ', $docName);
                $matchingDocs = $borrowerDocs->filter(function($d) use ($docNameString, $docName) {
                    $type = $d->documentType ? $d->documentType->name : '';
                    return strtolower($type) === strtolower($docNameString) || strtolower($type) === strtolower($docName);
                });

                if ($matchingDocs->isNotEmpty()) {
                    foreach ($matchingDocs as $bDoc) {
                        $docs[] = [
                            'name' => $docName,
                            'document_id' => $bDoc->id,
                            'file_name' => $bDoc->file_name,
                            'document_number' => $bDoc->document_number,
                            'document_type' => $bDoc->documentType ? $bDoc->documentType->name : null,
                            'file_type' => $bDoc->file_name ? strtoupper(pathinfo($bDoc->file_name, PATHINFO_EXTENSION)) : null,
                            'file_size' => $bDoc->file_size,
                            'status' => $bDoc->status,
                            'uploaded_on' => $bDoc->created_at ? $bDoc->created_at->format('M d, Y h:i A') : null,
                            'uploaded_timestamp' => $bDoc->created_at ? $bDoc->created_at->timestamp : 0,
                            'url' => $bDoc->url
                        ];
                    }
                } else {
                    $docs[] = [
                        'name' => $docName,
                        'document_id' => null,
                        'file_name' => null,
                        'document_number' => null,
                        'document_type' => null,
                        'file_type' => null,
                        'file_size' => null,
                        'status' => 'Not Uploaded',
                        'uploaded_on' => null,
                        'uploaded_timestamp' => 0,
                        'url' => null
                    ];
                }
            }
        } else {
            // If relations not loaded, just return names without URLs
            foreach ($uploaded as $docName) {
                $docs[] = [
                    'name' => $docName,
                    'document_id' => null,
                    'file_name' => null,
                    'document_number' => null,
                    'document_type' => null,
                    'file_type' => null,
                    'file_size' => null,
                    'status' => 'Not Uploaded',
                    'uploaded_on' => null,
                    'uploaded_timestamp' => 0,
                    'url' => null
                ];
            }
        }

        usort($docs, function ($a, $b) {
            $timeA = $a['uploaded_timestamp'] ?? 0;
            $timeB = $b['uploaded_timestamp'] ?? 0;
            return $timeB <=> $timeA;
        });

        return $docs;
    }

    public function getMatchedLendersAttribute()
    {
        if (empty($this->lender_ids)) {
            return [];
        }
        return Lender::whereIn('id', $this->lender_ids)
            ->select('id', 'name', 'contact_name', 'contact_email as email', 'contact_phone as phone')
            ->get();
    }

    public function getLenderNamesAttribute()
    {
        if (empty($this->lender_ids)) {
            return '';
        }
        return Lender::whereIn('id', $this->lender_ids)->pluck('name')->implode(', ');
    }

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

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'approved_by');
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

                // Search matching lenders
                $lenderIds = \App\Models\Lender\Lender::where('name', 'like', "%{$search}%")->pluck('id')->toArray();
                if (!empty($lenderIds)) {
                    foreach ($lenderIds as $lId) {
                        $q->orWhereJsonContains('lender_ids', $lId);
                    }
                }
            });
        }
        return $query;
    }
}
