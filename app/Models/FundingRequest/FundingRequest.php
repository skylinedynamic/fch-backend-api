<?php

namespace App\Models\FundingRequest;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Funding\FundingSequencing;
use App\Models\Lender\Lender;
use App\Models\Partner\Partner;
use App\Models\User\User;
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
        $borrower = $this->borrower;
        if (!$borrower || empty($this->funding_type)) {
            return [];
        }

        $category = \App\Models\Lender\LenderProductCategory::where('name', $this->funding_type)->first();
        if (!$category) {
            return [];
        }

        $visibilities = \App\Models\Funding\LenderVisibility::with('lender')
            ->where('lender_product_category_id', $category->id)
            ->get()
            ->unique('lender_id');

        $matchedLenders = [];

        // calculate TIB
        $tibMonths = 0;
        if ($borrower->business_start_date) {
            try {
                $tibMonths = \Carbon\Carbon::parse($borrower->business_start_date)->diffInMonths(\Carbon\Carbon::now());
            } catch (\Exception $e) {}
        } elseif ($borrower->years_in_business) {
            if (stripos($borrower->years_in_business, 'month') !== false) {
                preg_match('/(\d+)/', $borrower->years_in_business, $matches);
                if (isset($matches[1])) $tibMonths = (int)$matches[1];
            } elseif (stripos($borrower->years_in_business, 'year') !== false) {
                preg_match('/(\d+)/', $borrower->years_in_business, $matches);
                if (isset($matches[1])) $tibMonths = (int)$matches[1] * 12;
            }
        }

        $borrowerFico = (int) $borrower->fico_score;
        $borrowerBusinessCredit = (int) $borrower->paydex_score;
        $borrowerRevenue = (float) $borrower->monthly_revenue;
        $borrowerDeposits = (float) $borrower->average_monthly_deposits;

        foreach ($visibilities as $visibility) {
            if (!$visibility->lender) continue;

            $checks = [
                'fico' => false,
                'business_credit' => false,
                'revenue' => false,
                'tib' => false,
                'deposit' => false,
            ];
            $totalCriteria = 0;
            $metCriteria = 0;

            if (!is_null($visibility->min_fico)) {
                $totalCriteria++;
                if ($borrowerFico >= $visibility->min_fico) {
                    $checks['fico'] = true;
                    $metCriteria++;
                }
            } else {
                $checks['fico'] = true;
            }

            if (!is_null($visibility->min_business_credit_score)) {
                $totalCriteria++;
                if ($borrowerBusinessCredit >= $visibility->min_business_credit_score) {
                    $checks['business_credit'] = true;
                    $metCriteria++;
                }
            } else {
                $checks['business_credit'] = true;
            }

            if (!is_null($visibility->min_monthly_revenue)) {
                $totalCriteria++;
                if ($borrowerRevenue >= $visibility->min_monthly_revenue) {
                    $checks['revenue'] = true;
                    $metCriteria++;
                }
            } else {
                $checks['revenue'] = true;
            }

            if (!is_null($visibility->min_tib_months)) {
                $totalCriteria++;
                if ($tibMonths >= $visibility->min_tib_months) {
                    $checks['tib'] = true;
                    $metCriteria++;
                }
            } else {
                $checks['tib'] = true;
            }

            if (!is_null($visibility->min_monthly_deposits)) {
                $totalCriteria++;
                if ($borrowerDeposits >= $visibility->min_monthly_deposits) {
                    $checks['deposit'] = true;
                    $metCriteria++;
                }
            } else {
                $checks['deposit'] = true;
            }

            $score = $totalCriteria > 0 ? round(($metCriteria / $totalCriteria) * 100) : 100;
            if ($totalCriteria == 0) {
                $matchString = "Perfect Match";
                $matchHex = "#16a34a";
            } else {
                if ($score >= 80) {
                    $matchString = "Excellent Match";
                    $matchHex = "#16a34a";
                } elseif ($score >= 50) {
                    $matchString = "Good Match";
                    $matchHex = "#d97706";
                } else {
                    $matchString = "Low Match";
                    $matchHex = "#dc2626";
                }
            }

            $matchedLenders[] = [
                'id' => $visibility->lender->id,
                'name' => $visibility->lender->name,
                'phone' => $visibility->lender->contact_phone,
                'email' => $visibility->lender->contact_email,
                'product_category' => $category->name,
                'requirements' => [
                    'min_fico' => $visibility->min_fico,
                    'min_business_credit_score' => $visibility->min_business_credit_score,
                    'min_revenue' => $visibility->min_monthly_revenue,
                    'min_tib' => $visibility->min_tib_months,
                    'min_deposit' => $visibility->min_monthly_deposits,
                ],
                'checks' => $checks,
                'score' => $score,
                'score_text' => $matchString,
                'score_hex' => $matchHex,
                'matches_fraction' => "{$metCriteria}/" . ($totalCriteria ?: 4),
            ];
        }

        usort($matchedLenders, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return $matchedLenders;
    }

    public function getLenderNamesAttribute()
    {
        if (empty($this->assign_lender)) {
            return '';
        }
        return Lender::where('id', $this->assign_lender)->value('name') ?: '';
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

                // Search matching lenders
                $lenderIds = Lender::where('name', 'like', "%{$search}%")->pluck('id')->toArray();
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
