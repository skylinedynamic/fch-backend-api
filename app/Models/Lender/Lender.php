<?php

namespace App\Models\Lender;

use App\Models\Funding\LenderVisibility;
use App\Models\Lender\LenderProductCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lender extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_ACTIVE = 'Active';
    const STATUS_INACTIVE = 'Inactive';

    protected $fillable = [
        'name',
        'legal_name',
        'product_categories',
        'contact_name',
        'contact_title',
        'contact_email',
        'contact_phone',
        'website',
        'submission_portal_link',
        'submission_method',
        'submission_email',
        'iso_status',
        'status',
        'internal_notes',
        'last_verified_date',
    ];

    protected $casts = [
        'product_categories' => 'array',
        'last_verified_date' => 'date',
    ];

    /** Sent with every lender, so each screen can name it the same way. */
    protected $appends = ['display_name'];

    /**
     * How a lender is shown: "Lender Name - Legal Name" (R2 BUG-008). Two
     * lenders may share a name, never a legal name, so this tells them apart.
     * A row from before the legal name was required shows its name alone.
     */
    public function displayName(): string
    {
        $name = trim((string) $this->name);
        $legalName = trim((string) $this->legal_name);

        if ($legalName === '') {
            return $name;
        }

        return $name === '' ? $legalName : "{$name} - {$legalName}";
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->displayName();
    }

    /** A legal name as compared for uniqueness: case and spacing ignored. */
    public static function normalizeLegalName(?string $legalName): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $legalName)));
    }

    /** Whether another lender (deleted ones aside) already has this legal name. */
    public static function legalNameTaken(?string $legalName, int|string|null $ignoreId = null): bool
    {
        $wanted = self::normalizeLegalName($legalName);
        if ($wanted === '') {
            return false;
        }

        return self::query()
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->whereNotNull('legal_name')
            ->pluck('legal_name')
            ->contains(fn ($existing) => self::normalizeLegalName($existing) === $wanted);
    }

    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('legal_name', 'like', '%' . $search . '%')
                  ->orWhere('contact_name', 'like', '%' . $search . '%')
                  ->orWhere('contact_email', 'like', '%' . $search . '%')
                  ->orWhere('contact_phone', 'like', '%' . $search . '%')
                  ->orWhere('iso_status', 'like', '%' . $search . '%');
                  
                // Search by product category name
                $categoryIds = LenderProductCategory::where('name', 'like', '%' . $search . '%')->pluck('id');
                if ($categoryIds->isNotEmpty()) {
                    foreach ($categoryIds as $id) {
                        $q->orWhereJsonContains('product_categories', (string)$id)
                          ->orWhereJsonContains('product_categories', (int)$id);
                    }
                }
            });
        }
        return $query;
    }

    public function visibilities()
    {
        return $this->hasMany(LenderVisibility::class);
    }

    /**
     * A lender can be deleted only while no funding request has it as the
     * assigned lender (assign_lender) and no funding sequence shows it.
     * Being only matched to a request (lender_ids) does not keep it.
     */
    public function isDeletable(): bool
    {
        return !\App\Models\FundingRequest\FundingRequest::where('assign_lender', $this->id)->exists()
            // Only a live sequence's rules count - a deleted sequence leaves its rules behind.
            && !$this->visibilities()->whereHas('fundingSequencing')->exists();
    }

    /** Why this lender cannot be deleted, and what to do instead. */
    public function notDeletableReason(): string
    {
        return __('lender.delete_blocked');
    }
}
