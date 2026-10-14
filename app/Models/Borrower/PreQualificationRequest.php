<?php

namespace App\Models\Borrower;

use App\Models\Business\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User\User;
use App\Models\Company\Company;

class PreQualificationRequest extends Model
{
    use SoftDeletes;

    protected $table = 'pre_qualification_requests';

    protected $fillable = [
        'borrower_id',
        'partner_id',
        'lead_id',
        'business_id',
        'first_name',
        'last_name',
        'business_website',
        'email',
        'phone_number',
        'state_of_incorporation',
        'business_name',
        'industry_type',
        'business_structure',
        'business_start_date',
        'monthly_revenue',
        'annual_revenue',
        'years_in_business',
        'funding_requested_amount',
        'funding_urgency',
        'purpose_of_funding',
        'purpose_note',
        'personal_credit_score',
        'estimated_business_credit_score',
        'credit_score_source',
        'existing_business_debt',
        'debt_balance',
        'notes',
        'status',
        'status_note',
        'assign_to',
        // System-managed evaluation fields. Not borrower input, but written via
        // update() during submit//evaluation, which mass-assignment would
        // otherwise silently discard.
        'validation_status',
        'validation_version',
        'validation_summary',
        'validation_issues',
        'validated_at',
        'gpt_status',
        'gpt_generated_at',
    ];

    protected $casts = [
        'monthly_revenue' => 'decimal:2',
        'annual_revenue' => 'decimal:2',
        'funding_requested_amount' => 'decimal:2',
        'debt_balance' => 'decimal:2',
        'existing_business_debt' => 'boolean',
        'business_start_date' => 'date',
        'validation_issues' => 'array',
        'validated_at' => 'datetime',
        'gpt_generated_at' => 'datetime',
    ];

    public const VALIDATION_PASSED = 'PASSED';
    public const VALIDATION_INCORRECT = 'INCORRECT';
    public const VALIDATION_NEEDS_CORRECTION = 'INCORRECT';
    public const VALIDATION_SYSTEM_ERROR = 'SYSTEM_ERROR';

    public const GPT_PENDING = 'PENDING';
    public const GPT_COMPLETED = 'COMPLETED';
    public const GPT_INCORRECT = 'INCORRECT';
    public const GPT_NEEDS_CORRECTION = 'INCORRECT';
    public const GPT_FAILED = 'FAILED';

    public const ON_HOLD = 'On Hold';
    public const PENDING = 'Pending';
    public const INTAKE_RECEIVED = 'Intake Received';
    public const IN_REVIEW = 'In Review';
    public const APPROVED = 'Approved';
    public const DECLINED = 'Declined';
    // The automated review did not produce a usable result: the agent called
    // the borrower's information incorrect, or the evaluation itself failed.
    public const FAILED = 'Failed';

    public const ON_HOLD_CLASS = '';
    public const PENDING_CLASS = 'warning';
    public const INTAKE_RECEIVED_CLASS = 'info';
    public const IN_REVIEW_CLASS = 'primary';
    public const APPROVED_CLASS = 'success';
    public const DECLINED_CLASS = 'danger';
    public const FAILED_CLASS = 'danger';

    public const STATUS_CLASS = [
        self::ON_HOLD => self::ON_HOLD_CLASS,
        self::PENDING => self::PENDING_CLASS,
        self::INTAKE_RECEIVED => self::INTAKE_RECEIVED_CLASS,
        self::IN_REVIEW => self::IN_REVIEW_CLASS,
        self::APPROVED => self::APPROVED_CLASS,
        self::DECLINED => self::DECLINED_CLASS,
        self::FAILED => self::FAILED_CLASS,
    ];

    public const STATUS = [
        self::ON_HOLD => self::ON_HOLD,
        self::PENDING => self::PENDING,
        self::INTAKE_RECEIVED => self::INTAKE_RECEIVED,
        self::IN_REVIEW => self::IN_REVIEW,
        self::APPROVED => self::APPROVED,
        self::DECLINED => self::DECLINED,
        self::FAILED => self::FAILED,
    ];

    /**
     * Move the workflow status to match how the automated review ended:
     * Failed when the information was called incorrect or the evaluation could
     * not be produced, Pending when it completed.
     *
     * Only ever moves between those two. Once a reviewer has taken the file
     * further (Intake Received, In Review, Approved, Declined) a re-run must
     * not undo their decision. The caller saves.
     */
    public function applyUnderwritingOutcome(bool $succeeded): void
    {
        if (!in_array($this->status, [self::PENDING, self::FAILED], true)) {
            return;
        }

        $this->status = $succeeded ? self::PENDING : self::FAILED;
    }

    /**
     * How long a status note may be.
     *
     * Status Notes is a short reviewer note, not a memo — the full underwriting
     * write-up lives in the AI Underwriting Result tab. The generated note
     * ("AUE stance: … Next action: …") tops out around 70 characters, so this
     * leaves room for a reviewer to add context without the field becoming a
     * second place to store the memo.
     *
     * Single source of truth for the limit: the request rule, the generated-note
     * truncation and the admin form's counter all derive from it. If it changes,
     * STATUS_NOTE_MAX_LENGTH in the admin front-end's PreQualificationDetails
     * must change with it.
     */
    public const STATUS_NOTE_MAX_LENGTH = 200;

      public const PURPOSE_OF_FUNDING_OPTIONS = [
        'Working Capital' => 'Working Capital',
        'Equipment'       => 'Equipment',
        'Inventory'       => 'Inventory',
        'Marketing'       => 'Marketing',
        'Expansion'       => 'Expansion',
        'Other'           => 'Other',
    ];

    public function getStatusClassAttribute(): string
    {
        return self::STATUS_CLASS[$this->status] ?? '';
    }

    /**
     * The request's own business_start_date, falling back to the linked
     * Borrower's business_start_date when this record's own value is blank
     * (e.g. the borrower entered it during account setup, not this prequal form).
     */
    public function resolvedBusinessStartDate(): ?string
    {
        return $this->business_start_date ?? $this->borrower?->business_start_date ?? null;
    }

    /**
     * Human-readable time-in-business, preferring a calculation from the
     * resolved start date (own record, then borrower fallback) over the
     * request's own free-text years_in_business, which may be stale or absent.
     */
    public function resolvedYearsInBusiness(): ?string
    {
        $startDate = $this->resolvedBusinessStartDate();

        return $startDate ? calculateTimeInBusiness($startDate) : ($this->years_in_business ?: null);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }


    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
    }

    public function partner()
    {
        return $this->belongsTo(\App\Models\Partner\Partner::class);
    }

    public function lead()
    {
        return $this->belongsTo(\App\Models\Lead\Lead::class);
    }

    /**
     * The pre-qualifications on a user's Intake & Review book: a partner's
     * team (user_type Partner) works its own partner's, the host's team
     * (user_type Admin) every partner's, a borrower only their own.
     */
    public function scopeReviewableBy(Builder $query, ?\App\Models\User\User $user): Builder
    {
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->entity_type === Borrower::class) {
            return $query->where($this->qualifyColumn('borrower_id'), $user->entity_id);
        }

        if ($user->user_type !== \App\Models\User\User::ADMIN) {
            return $query->where($this->qualifyColumn('partner_id'), $user->partner_id);
        }

        return $query;
    }

    /**
     * Scope a query to search across common text fields.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (!$term) {
            return $query;
        }

        $like = "%{$term}%";

        return $query->where(function (Builder $q) use ($like) {
            $q->where('first_name', 'like', $like)
              ->orWhere('last_name', 'like', $like)
              ->orWhere('business_name', 'like', $like)
              ->orWhere('email', 'like', $like)
              ->orWhere('phone_number', 'like', $like);
        });
    }
}
