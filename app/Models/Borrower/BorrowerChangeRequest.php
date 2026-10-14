<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Factories\HasFactory;
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

    /** Fields whose values are masked wherever they leave the app (email). */
    public const MASKED_SSN_FIELDS = ['ssn', 'last_4_ssn'];
    public const MASKED_EIN_FIELDS = ['business_ein'];

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
