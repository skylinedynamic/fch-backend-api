<?php

namespace App\Models\Lead;

use App\Http\Middleware\EnsureHostPartner;
use App\Models\Contact\Contact;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\HasEncryptedSsn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Contracts\Activity;

class Lead extends Model
{
    use HasFactory, SoftDeletes;
    // The full SSN, encrypted in ssn_encrypted - as on a client's profile.
    use HasEncryptedSsn;

    protected $table = 'leads';

    protected $guarded = [];

    const PARTNER_TYPE_LEAD = 'Partner';
    const BORROWER_TYPE_LEAD = 'Borrower';

    const DEFAULT_COUNTRY_CODE = '+1';
    // The website pre-qualification asks for a US state of incorporation.
    const DEFAULT_COUNTRY = 'United States';

    const STATUS_NEW_LEAD = 'New Lead';
    const STATUS_CONTACT_INITIATED = 'Contact Initiated';
    const STATUS_LEAD_IDENTIFIED = 'Lead Identified';
    const STATUS_MEETING_ARRANGED = 'Meeting Arranged';
    const STATUS_APPLICATION_ALLOWED = 'Application Allowed';
    const STATUS_FOLLOW_UP = 'Follow Up';
    const STATUS_NEGOTIATION = 'Negotiation';
    const STATUS_LEAD_COMPLETED = 'Lead Completed';
    const STATUS_LEAD_DECLINED = 'Lead Declined';
    const STATUS_PROCRASTINATION = 'Procrastination';

    const LEAD_SOURCE_REFERRAL_PARTNER = 'Referral Partner';
    const LEAD_SOURCE_CLIENT_REFERRAL = 'Client Referral';
    const LEAD_SOURCE_PERSONAL_NETWORK = 'Personal Network (Friends/Family)';
    const LEAD_SOURCE_SOCIAL_MEDIA = 'Social Media';
    const LEAD_SOURCE_WEBSITE = 'Website';
    const LEAD_SOURCE_NETWORKING_EVENT = 'Networking Event';
    const LEAD_SOURCE_COLD_OUTREACH = 'Cold Outreach';
    const LEAD_SOURCE_PROFESSIONAL_REFERRAL = 'Professional Referral (CPA/Realtor/LO/etc.)';
    const LEAD_SOURCE_OTHER = 'Other';

    public static function getSourceOptions(): array
    {
        return [
            self::LEAD_SOURCE_REFERRAL_PARTNER,
            self::LEAD_SOURCE_CLIENT_REFERRAL,
            self::LEAD_SOURCE_PERSONAL_NETWORK,
            self::LEAD_SOURCE_SOCIAL_MEDIA,
            self::LEAD_SOURCE_WEBSITE,
            self::LEAD_SOURCE_NETWORKING_EVENT,
            self::LEAD_SOURCE_COLD_OUTREACH,
            self::LEAD_SOURCE_PROFESSIONAL_REFERRAL,
            self::LEAD_SOURCE_OTHER,
        ];
    }

    /**
     * Get statuses that are considered "qualified" for statistics.
     */
    public static function getQualifiedStatuses(): array
    {
        return [
            self::STATUS_LEAD_IDENTIFIED,
            self::STATUS_MEETING_ARRANGED,
            self::STATUS_APPLICATION_ALLOWED,
            self::STATUS_NEGOTIATION,
            self::STATUS_LEAD_COMPLETED,
        ];
    }

    protected $casts = [
        'business_start_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the salesperson responsible for the lead.
     */
    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    /**
     * Get the partner associated with the lead.
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    /**
     * Get the referral partner associated with the lead.
     */
    public function referralPartner(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'referral_partner_id');
    }

    /**
     * Get the user who created the lead.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope: apply text search across lead fields.
     */
    public function scopeSearch($query, ?string $search)
    {
        if (!is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->whereContains('leads.lead_id', $search)
                ->orWhereContains('leads.contact_person', $search)
                ->orWhereContains('leads.company_name', $search)
                ->orWhereContains('leads.phone_number', $search)
                ->orWhereContains('leads.email', $search)
                ->orWhereContains('leads.lead_type', $search)
                ->orWhereContains('leads.status', $search)
                ->orWhereHas(
                    'salesperson',
                    function ($sq) use ($search) {
                        $sq->whereContains('name', $search);
                    }
                )
                ->orWhereHas(
                    'partner',
                    function ($sq) use ($search) {
                        $sq->whereContains('full_name', $search);
                    }
                )
                ->orWhereHas('referralPartner', function ($sq) use ($search) {
                    $sq->whereContains('first_name', $search)
                        ->orWhereContains('last_name', $search)
                        ->orWhereContains('company_name', $search);
                });
        });
    }

    /**
     * The leads a user may see and work: every partner's for the host partner
     * and its staff, only their own partner's for anyone else.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        if (EnsureHostPartner::allows($user)) {
            return $query;
        }

        return $query->where('leads.partner_id', $user?->partner_id);
    }

    /**
     * Whether this user converting this lead does so on the lead partner's
     * behalf: the host converting another partner's borrower lead. The
     * borrower stays that partner's either way.
     */
    public function convertsOnBehalfOfPartnerFor(?User $user): bool
    {
        return $this->lead_type === self::BORROWER_TYPE_LEAD
            && $this->partner_id !== null
            && (int) $this->partner_id !== (int) $user?->partner_id
            && EnsureHostPartner::allows($user);
    }
}
