<?php

namespace App\Models\Borrower;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User\User;

class BorrowerChangeRequest extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'Pending';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_REJECTED = 'Rejected';

    protected $fillable = [
        'partner_id',
        'borrower_id',
        'field_name',
        'field_type',
        'visible_field_name',
        'db_field_name',
        'entity_type',
        'entity_id',
        'old_value',
        'new_value',
        'status',
        'updated_by',
        'note',
    ];

    /**
     * Fields whose values are masked wherever they leave the app (email), and
     * kept encrypted in old_value/new_value. `last_4_ssn` only on requests filed
     * before the full SSN replaced it.
     */
    public const MASKED_SSN_FIELDS = ['ssn', 'last_4_ssn'];
    public const MASKED_EIN_FIELDS = ['business_ein'];

    private const VALUE_COLUMNS = ['old_value', 'new_value'];

    /**
     * An SSN is encrypted on its way into old_value/new_value, whatever order
     * the attributes were filled in - by then field_name is known.
     */
    protected static function booted(): void
    {
        static::saving(function (self $request) {
            if (!$request->holdsSsn()) {
                return;
            }

            foreach (self::VALUE_COLUMNS as $column) {
                $value = $request->attributes[$column] ?? null;

                if ($value !== null && $value !== '' && self::decrypted($value) === null) {
                    $request->attributes[$column] = Crypt::encryptString((string) $value);
                }
            }
        });
    }

    protected function oldValue(): Attribute
    {
        return Attribute::get(fn (?string $value) => $this->readValue($value));
    }

    protected function newValue(): Attribute
    {
        return Attribute::get(fn (?string $value) => $this->readValue($value));
    }

    /** An SSN or EIN change: listed masked, its numbers in full only on reveal (BUG-014). */
    public function isMaskedField(): bool
    {
        return in_array($this->field_name, [...self::MASKED_SSN_FIELDS, ...self::MASKED_EIN_FIELDS], true);
    }

    private function holdsSsn(): bool
    {
        return in_array($this->attributes['field_name'] ?? null, self::MASKED_SSN_FIELDS, true);
    }

    /** An SSN decrypted; a row from before encryption, or any other field, as stored. */
    private function readValue(?string $value): ?string
    {
        if ($value === null || $value === '' || !$this->holdsSsn()) {
            return $value;
        }

        return self::decrypted($value) ?? $value;
    }

    private static function decrypted(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * A value of this request as it may be written into an email: an SSN or
     * EIN shows its last four digits only (just the last four of an SSN is
     * hidden entirely); every other field, a date of birth included, as is.
     */
    public function maskedValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $digits = preg_replace('/\D/', '', $value);

        if (in_array($this->field_name, self::MASKED_SSN_FIELDS, true)) {
            return strlen($digits) === 9 ? \App\Rules\Ssn::mask($digits) : str_repeat('*', max(4, strlen($digits)));
        }

        if (in_array($this->field_name, self::MASKED_EIN_FIELDS, true)) {
            return strlen($digits) >= 4 ? '**-***' . substr($digits, -4) : '****';
        }

        return $value;
    }

    /**
     * Get the parent change requestable model (polymorphic relationship).
     */
    public function changeRequestable()
    {
        return $this->morphTo(null, 'entity_type', 'entity_id');
    }

    /**
     * Get the borrower that owns the change request.
     */
    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
    }

    /**
     * Get the user who updated the change request.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
