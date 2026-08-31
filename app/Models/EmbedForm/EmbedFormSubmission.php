<?php

namespace App\Models\EmbedForm;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmbedFormSubmission extends Model
{
    protected $table = 'embedded_form_submissions';

    protected $guarded = [];

    protected $casts = [
        'birthday' => 'date',
        'desired_funding_amount' => 'decimal:2',
        'monthly_housing_payment' => 'decimal:2',
        'annual_income' => 'decimal:2',
        'credit_score' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = ['ssn_encrypted'];

    const STATUS_NEW = 'new';
    const STATUS_REVIEWING = 'reviewing';
    const STATUS_CONVERTED = 'converted';
    const STATUS_REJECTED = 'rejected';

    public static function getStatuses(): array
    {
        return [
            self::STATUS_NEW,
            self::STATUS_REVIEWING,
            self::STATUS_CONVERTED,
            self::STATUS_REJECTED,
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function formConfig(): BelongsTo
    {
        return $this->belongsTo(EmbedFormConfig::class, 'form_config_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmbedFormAttachment::class, 'submission_id');
    }

    public function convertedBorrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'converted_borrower_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
