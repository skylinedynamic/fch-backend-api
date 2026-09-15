<?php

namespace App\Models\EmbedForm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasPersonName;

/**
 * A co-borrower captured by the public embedded form.
 *
 * Mirrors FundingApplicationCoBorrower, except the SSN is held encrypted: the
 * form is unauthenticated, so nothing sensitive is stored in the clear until a
 * partner converts the submission.
 */
class EmbedFormCoBorrower extends Model
{
    use HasPersonName;

    use SoftDeletes;

    protected $table = 'embedded_form_co_borrowers';

    protected $guarded = [];

    protected $casts = [
        'date_of_birth' => 'date',
        'ownership_percentage' => 'decimal:2',
    ];

    protected $hidden = ['ssn_encrypted'];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(EmbedFormSubmission::class, 'submission_id');
    }

    public function getFullNameAttribute(): string
    {
        return $this->person_name;
    }
}
