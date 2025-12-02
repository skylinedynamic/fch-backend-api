<?php

namespace App\Models\Partner;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User\User;
use App\Models\Business\Business;
use App\Models\Employee\Employee;
use App\Models\Borrower\Borrower;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Partner extends Model
{
    use HasFactory, SoftDeletes;



    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'partners';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'is_host',
        'full_name',
        'company_name',
        'email',
        'phone_number',
        'contact_person',
        'contact_person_email',
        'contact_person_phone_number',
        'contact_person_relation',
        'partner_type',
        'status',
        'state',
        'city',
        'postal_code',
        'address_line_1',
        'address_line_2',
        'country',
        'country_code',
        'joining_date',
        'company_logo',
        'assign_to',
        'partner_image',
        'tax_id',
        'website'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public const STATUS = [
        'active' => 'active',
        'inactive' => 'inactive',
    ];

    /**
     * Users that belong to this partner (via users.partner_id).
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'partner_id');
    }

    
    /**
     * Assigned user for this partner (via partners.assign_to).
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assign_to');
    }

    /**
     * The user that represents this Partner entity (polymorphic users.entity_type/id).
     */
    public function partnerUser(): MorphOne
    {
        return $this->morphOne(User::class, 'entity');
    }

    /**
     * Scope: apply text search across common partner fields.
     */
    public function scopeSearch($query, ?string $search)
    {
        if (!is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('company_name', 'LIKE', "%{$search}%")
              ->orWhere('email', 'LIKE', "%{$search}%")
              ->orWhere('phone_number', 'LIKE', "%{$search}%")
              ->orWhere('full_name', 'LIKE', "%{$search}%")
              ->orWhere('contact_person', 'LIKE', "%{$search}%")
              ->orWhere('contact_person_email', 'LIKE', "%{$search}%")
              ->orWhere('contact_person_phone_number', 'LIKE', "%{$search}%")
              ->orWhere('partner_type', 'LIKE', "%{$search}%")
              ->orWhereHas('assignedUser', function ($userQ) use ($search) {
                  $userQ->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%");
              });
        });
    }
    /**
     * Get the borrowers for the partner.
     */
    public function borrowers(): HasMany
    {
        return $this->hasMany(Borrower::class, 'partner_id');
    }

    /**
     * Get the documents for the partner.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(PartnerDocument::class, 'partner_id');
    }

    /**
     * Get the subscription for the partner.
     */
    public function subscription(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PartnerSubscription::class, 'partner_id');
    }
}