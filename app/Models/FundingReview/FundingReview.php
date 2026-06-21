<?php

namespace App\Models\FundingReview;

use App\Models\Borrower\Borrower;
use App\Models\Funding\FundingSequencing;
use App\Models\Partner\Partner;
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
    ];

    protected $casts = [
        'documents_uploaded' => 'array',
        'is_approved' => 'boolean',
        'approved_at' => 'datetime',
    ];

    protected $appends = ['documents_with_urls'];

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
                $bDoc = $borrowerDocs->first(function($d) use ($docNameString, $docName) {
                    $type = $d->documentType ? $d->documentType->name : '';
                    return strtolower($type) === strtolower($docNameString) || strtolower($type) === strtolower($docName);
                });

                $docs[] = [
                    'name' => $docName,
                    'document_id' => $bDoc ? $bDoc->id : null,
                    'file_name' => $bDoc ? $bDoc->file_name : null,
                    'document_number' => $bDoc ? $bDoc->document_number : null,
                    'document_type' => $bDoc && $bDoc->documentType ? $bDoc->documentType->name : null,
                    'file_type' => $bDoc && $bDoc->file_name ? strtoupper(pathinfo($bDoc->file_name, PATHINFO_EXTENSION)) : null,
                    'file_size' => $bDoc ? $bDoc->file_size : null,
                    'status' => $bDoc ? $bDoc->status : 'Not Uploaded',
                    'uploaded_on' => $bDoc ? $bDoc->created_at->format('M d, Y h:i A') : null,
                    'url' => $bDoc ? $bDoc->url : null
                ];
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
                    'url' => null
                ];
            }
        }
        return $docs;
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
