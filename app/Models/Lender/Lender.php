<?php

namespace App\Models\Lender;

use App\Models\Funding\LenderVisibility;
use App\Models\Lender\LenderProductCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

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
        // Location, all optional (country, state and city by name).
        'country',
        'state',
        'city',
        'address',
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

    /** Lender Code: this prefix, four random digits, then the lender's id. */
    const LENDER_CODE_PREFIX = 'LND-';

    /**
     * Every new lender gets its Lender Code once it has an id; it is never
     * typed in or changed (not fillable).
     */
    protected static function booted(): void
    {
        static::created(function (Lender $lender) {
            $lender->lender_code = self::lenderCodeFor($lender->id);
            $lender->saveQuietly();
        });
    }

    /**
     * "LND-" . four random digits . the lender's id - lender 5: "LND-93245".
     * The id at the end makes every code unique: two codes of the same length
     * end in ids of the same length, and ids never repeat.
     */
    public static function lenderCodeFor(int $id, ?int $digits = null): string
    {
        return sprintf('%s%04d%d', self::LENDER_CODE_PREFIX, $digits ?? random_int(0, 9999), $id);
    }

    /** Whether this is a Lender Code in the current format for lender $id. */
    public static function isLenderCodeFor(?string $code, int $id): bool
    {
        return is_string($code) && preg_match('/^' . preg_quote(self::LENDER_CODE_PREFIX, '/') . '\d{4}' . $id . '$/', $code) === 1;
    }

    /**
     * Gives a Lender Code to every lender - deleted ones too - that has none,
     * or one not in the current format for its id (the first version of the
     * migration gave "LND-" + 4 digits, without the id). A code already in
     * the format is kept. Returns how many were given.
     */
    public static function fillMissingLenderCodes(): int
    {
        $given = 0;

        self::withTrashed()->orderBy('id')->get(['id', 'lender_code'])->each(function (Lender $lender) use (&$given) {
            if (self::isLenderCodeFor($lender->lender_code, $lender->id)) {
                return;
            }
            self::withTrashed()->whereKey($lender->id)->update(['lender_code' => self::lenderCodeFor($lender->id)]);
            $given++;
        });

        return $given;
    }

    /**
     * How a lender is shown: "Lender Name - Legal Name" (R2 BUG-008). Two
     * lenders may share both since 2026-11-11; the Lender ID tells them apart.
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

    /** A name or legal name as compared for duplicates: case and spacing ignored. */
    public static function normalizeLegalName(?string $legalName): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $legalName)));
    }

    /**
     * The lenders (deleted ones aside) whose name or legal name is this one -
     * case and spacing ignored. Names may repeat; Add/Edit Lender warns.
     *
     * @return Collection<int, array{id: int, lender_code: ?string, name: string, legal_name: ?string}>
     */
    public static function withSame(string $column, ?string $value, int|string|null $ignoreId = null): Collection
    {
        $wanted = self::normalizeLegalName($value);
        if ($wanted === '') {
            return collect();
        }

        return self::query()
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->whereNotNull($column)
            ->orderBy('id')
            ->get(['id', 'lender_code', 'name', 'legal_name'])
            ->filter(fn (Lender $lender) => self::normalizeLegalName($lender->{$column}) === $wanted)
            ->map(fn (Lender $lender) => [
                'id' => $lender->id,
                'lender_code' => $lender->lender_code,
                'name' => $lender->name,
                'legal_name' => $lender->legal_name,
            ])
            ->values();
    }

    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('lender_code', 'like', '%' . $search . '%')
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

    /** Validation: an id of a lender that can be assigned - Active and not deleted. */
    public static function assignableRule(): Exists
    {
        return Rule::exists('lenders', 'id')->where('status', self::STATUS_ACTIVE)->whereNull('deleted_at');
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
