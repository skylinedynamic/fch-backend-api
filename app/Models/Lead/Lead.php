<?php

namespace App\Models\Lead;

use App\Models\Contact\Contact;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Contracts\Activity;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'leads';

    protected $guarded = [];

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
            $q->where('leads.lead_id', 'like', "%{$search}%")
                ->orWhere('leads.contact_person', 'like', "%{$search}%")
                ->orWhere('leads.company_name', 'like', "%{$search}%")
                ->orWhere('leads.phone_number', 'like', "%{$search}%")
                ->orWhere('leads.email', 'like', "%{$search}%")
                ->orWhere('leads.lead_type', 'like', "%{$search}%")
                ->orWhere('leads.status', 'like', "%{$search}%")
                ->orWhereHas(
                    'salesperson',
                    function ($sq) use ($search) {
                        $sq->where('name', 'like', "%{$search}%");
                    }
                )
                ->orWhereHas(
                    'partner',
                    function ($sq) use ($search) {
                        $sq->where('full_name', 'like', "%{$search}%");
                    }
                )
                ->orWhereHas('referralPartner', function ($sq) use ($search) {
                    $sq->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%");
                });
        });
    }
}
