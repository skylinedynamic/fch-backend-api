<?php

namespace App\Models\CreditBuilder;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditAccount extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_ACTIVE = 'Active';
    const STATUS_INACTIVE = 'Inactive';

    protected $table = 'credit_accounts';

    protected $guarded = ['id'];

    protected $appends = ['full_image_url'];

    /**
     * Get the full URL for the credit account image.
     *
     * @return string|null
     */
    public function getFullImageUrlAttribute()
    {
        if (! $this->image_url) {
            return null;
        }

        // A logo given as a full URL is used as it is; storage/ in front of it
        // made a link that never loads (BUG-148).
        if (preg_match('#^https?://#i', $this->image_url)) {
            return encodeUrl($this->image_url);
        }

        return encodeUrl(asset("storage/{$this->image_url}"));
    }

    /**
     * Get the applications associated with the credit account.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function applications()
    {
        return $this->hasMany(CreditAccountApplication::class);
    }

    /** Borrowers' applications for this card as a step of a Capital Access play. */
    public function playApplications()
    {
        return $this->hasManyThrough(
            CapitalAccessApplication::class,
            CapitalAccessSequence::class,
            'credit_account_id',
            'capital_access_sequence_id'
        );
    }

    /**
     * Whether a borrower has applied for this card - from Credit Accounts or
     * through a play. Such a card is kept (inactivate it instead); others can
     * be deleted (BUG-042). Uses withApplicationFlags() when loaded.
     */
    public function hasBorrowerApplications(): bool
    {
        if (array_key_exists('applications_exists', $this->attributes)) {
            return (bool) $this->applications_exists || (bool) $this->play_applications_exists;
        }

        return $this->applications()->exists() || $this->playApplications()->exists();
    }

    /** Loads hasBorrowerApplications() for a list in two subqueries. */
    public function scopeWithApplicationFlags($query)
    {
        return $query->withExists(['applications', 'playApplications']);
    }

    /**
     * Scope search for filtering credit accounts.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|null $search
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearch($query, ?string $search)
    {
        if (!$search) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('card_name', 'LIKE', "%{$search}%")
                ->orWhere('issuer', 'LIKE', "%{$search}%")
                ->orWhere('credit_category', 'LIKE', "%{$search}%")
                ->orWhere('status', 'LIKE', "%{$search}%")
                ->orWhere('typical_personal_fico_min', 'LIKE', "%{$search}%")
                ->orWhere('time_in_business_min_months', 'LIKE', "%{$search}%");
        });
    }

    /**
     * The accounts a borrower is offered: switched on by the host, and within
     * the borrower's FICO score and time in business. The one rule for both
     * the list and apply - apply checking less than the list showed is how an
     * application came to be recorded against an account never offered.
     */
    /** Switched on by the host: not Inactive (no status reads as Active). */
    public function scopeAvailable($query)
    {
        return $query->where(fn ($q) => $q->where('status', '!=', self::STATUS_INACTIVE)->orWhereNull('status'));
    }

    public function scopeOfferedTo($query, ?\App\Models\Borrower\Borrower $borrower)
    {
        $query->available();

        if ($borrower) {
            $score = (int) ($borrower->fico_score ?? 0);
            $months = $borrower->business_start_date
                ? \Carbon\Carbon::parse($borrower->business_start_date)->diffInMonths()
                : 0;

            $query->where('typical_personal_fico_min', '<=', $score)
                ->where('time_in_business_min_months', '<=', (int) $months);
        }

        return $query;
    }
}
