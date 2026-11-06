<?php

namespace App\Models\FundingRequest;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Funding\FundingSequencing;
use App\Models\Lender\Lender;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Funding\LenderMatchService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FundingRequest extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_PENDING = 'Pending';
    const STATUS_IN_PROGRESS = 'In Progress';
    const STATUS_APPROVED = 'Approved';
    const STATUS_ACCEPTED = 'Accepted';
    const STATUS_REJECTED = 'Rejected';
    const STATUS_CANCELED = 'Cancelled';

    /**
     * The statuses a change to is emailed (product decision 2026-11-04) - each
     * time the request reaches one. Pending, In Progress and Accepted are told
     * in the bell only.
     */
    const EMAILED_STATUSES = [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELED];

    /** The longest rejection note (the admin's Reason / Note counter shows the same). */
    const REJECT_REASON_MAX_LENGTH = 10000;

    const APPLICATION_STATUS_NOT_STARTED = 'Not Started';
    // const STATUS_APPLICATION_STARTED = 'Funding Application Started';
    // const STATUS_APPLICATION_IN_PROGRESS = 'Funding Application In Progress';
    // const STATUS_APPLICATION_SUBMITTED = 'Funding Application Submitted';

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
        'assign_lender',
        'reminder_step',
    ];

    protected $casts = [
        'documents_uploaded' => 'array',
        'is_approved' => 'boolean',
        'approved_at' => 'datetime',
        'lender_ids' => 'array',
    ];

    protected $appends = ['documents_with_urls', 'matched_lenders', 'lender_names', 'host_email'];

    public function getHostEmailAttribute()
    {
        $host = Partner::where('is_host', 1)->first();
        return $host ? $host->email : '';
    }

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
                // Curly and straight apostrophes are the same name ("Driver’s License").
                $normalize = fn($s) => strtolower(str_replace(['’', '‘'], "'", (string) $s));
                $matchingDocs = $borrowerDocs->filter(function($d) use ($docNameString, $docName, $normalize) {
                    $type = $normalize($d->documentType ? $d->documentType->name : '');
                    return $type === $normalize($docNameString) || $type === $normalize($docName);
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

    /**
     * The lenders for this request's funding type, best match first - see
     * LenderMatchService (shared with the funding application details page).
     */
    public function getMatchedLendersAttribute()
    {
        // The same list as its application's page: the application's Preferred
        // + Additional Funding Types (request 2026-11-05). With no application,
        // the request's own funding type.
        $application = $this->fundingApplication;
        if ($application) {
            $application->setRelation('fundingRequest', $this);

            return app(LenderMatchService::class)->match($this->borrower, $application->lenderCategories());
        }

        return app(LenderMatchService::class)->match($this->borrower, [$this->funding_type]);
    }

    /**
     * Whether the lender may be assigned or changed now: its funding
     * application's rule (FundingApplication::canAssignLender) when it has one -
     * past the editable statuses the lender is fixed, from either page.
     */
    public function canAssignLender(): bool
    {
        $application = $this->fundingApplication;
        if (! $application) {
            return true;
        }
        $application->setRelation('fundingRequest', $this);

        return $application->canAssignLender();
    }

    public function getLenderNamesAttribute()
    {
        if (empty($this->assign_lender)) {
            return '';
        }
        // "Lender Name - Legal Name", as everywhere a lender is shown (R2 BUG-008).
        return Lender::find($this->assign_lender)?->displayName() ?? '';
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
    }

    /** A funding request is reachable exactly as far as its borrower is - see Borrower::scopeVisibleTo(). */
    public function scopeVisibleTo($query, ?\App\Models\User\User $user)
    {
        return $query->whereHas('borrower', fn ($borrowers) => $borrowers->visibleTo($user));
    }

    public function fundingSequence()
    {
        return $this->belongsTo(FundingSequencing::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function fundingApplication()
    {
        return $this->hasOne(FundingApplication::class, 'funding_request_id')->latestOfMany();
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
                // Search partner relations
                ->orWhereHas('partner', function ($qPartner) use ($search) {
                    $qPartner->where('full_name', 'like', "%{$search}%");
                })
                // Search funding sequence relations
                ->orWhereHas('fundingSequence', function ($qSequence) use ($search) {
                    $qSequence->where('name', 'like', "%{$search}%");
                })
                // Search own fields
                ->orWhere('funding_type', 'like', "%{$search}%")
                ->orWhere('use_of_funds', 'like', "%{$search}%")
                ->orWhere('requested_amount', 'like', "%{$search}%")
                // The amount as the list shows it ("$300,000"): without the $
                // and commas it is the stored number.
                ->when(preg_match('/^\$?[\d,]+(\.\d+)?$/', $search) && strpbrk($search, '$,') !== false, function ($qAmount) use ($search) {
                    $qAmount->orWhere('requested_amount', 'like', '%' . str_replace(['$', ','], '', $search) . '%');
                })
                ->orWhere('status', 'like', "{$search}%")
                
                // Search funding application status
                ->orWhereHas('fundingApplication', function ($qApp) use ($search) {
                    $qApp->where('status', 'like', "{$search}%");
                });

                // If search string matches "Not Started", include approved requests without application
                if (stripos(self::APPLICATION_STATUS_NOT_STARTED, $search) !== false) {
                    $q->orWhere(function ($sq) {
                        $sq->where('status', self::STATUS_APPROVED)
                           ->doesntHave('fundingApplication');
                    });
                }

                // Search matching lenders: the one assigned (the ASSIGNED LENDER
                // column) as well as the matched ones.
                $lenderIds = Lender::where('name', 'like', "%{$search}%")->pluck('id')->toArray();
                if (!empty($lenderIds)) {
                    $q->orWhereIn('assign_lender', $lenderIds);
                    foreach ($lenderIds as $lId) {
                        $q->orWhereJsonContains('lender_ids', $lId);
                    }
                }
            });
        }
        return $query;
    }
}
