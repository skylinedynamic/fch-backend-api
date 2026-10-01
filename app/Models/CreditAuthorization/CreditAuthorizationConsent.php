<?php

namespace App\Models\CreditAuthorization;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\CreditProfileRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One client's agreement to one credit pull.
 *
 * Written once and then left alone, apart from the pull details that arrive
 * afterwards. See the migration for why the wording is stored rather than
 * referenced.
 *
 * @property string $authorization_version
 */
class CreditAuthorizationConsent extends Model
{
    protected $fillable = [
        'borrower_id',
        'credit_profile_request_id',
        'authorization_version',
        'authorization_url',
        'credit_authorization_text',
        'e_consent_text',
        'credit_authorization_accepted',
        'e_consent_accepted',
        'consented_at',
        'ip_address',
        'user_agent',
        'isoftpull_transaction_id',
        'pulled_at',
    ];

    protected $casts = [
        'credit_authorization_accepted' => 'boolean',
        'e_consent_accepted' => 'boolean',
        'consented_at' => 'datetime',
        'pulled_at' => 'datetime',
        // NOT a float. '1.0' has to come back out as it went in, because it is
        // matched against the version history published on the authorisation page.
        'authorization_version' => 'string',
    ];

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function creditProfileRequest(): BelongsTo
    {
        return $this->belongsTo(CreditProfileRequest::class);
    }

    /** Both boxes ticked - the only state in which a pull is authorised. */
    public function isComplete(): bool
    {
        return $this->credit_authorization_accepted && $this->e_consent_accepted;
    }

    /** How the PDF and the record cite the public copy of the wording. */
    public function citation(): string
    {
        return "Credit Authorization v{$this->authorization_version} — {$this->authorization_url}";
    }

    /**
     * One client's authorisations as the screens read them, newest first.
     *
     * Shaped here rather than in a controller because two places serve it - the
     * request details response and anything that lists them later - and the
     * fields shown are a decision about what leaves the server, not about one
     * endpoint.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forBorrower(int $borrowerId): array
    {
        return static::where('borrower_id', $borrowerId)
            ->orderByDesc('consented_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (self $consent) => [
                'id' => $consent->id,
                'authorization_version' => $consent->authorization_version,
                'authorization_url' => $consent->authorization_url,
                'citation' => $consent->citation(),
                'credit_authorization_accepted' => $consent->credit_authorization_accepted,
                'e_consent_accepted' => $consent->e_consent_accepted,
                'consented_at' => optional($consent->consented_at)->toIso8601String(),
                'ip_address' => $consent->ip_address,
                'user_agent' => $consent->user_agent,
                'isoftpull_transaction_id' => $consent->isoftpull_transaction_id,
                'pulled_at' => optional($consent->pulled_at)->toIso8601String(),
            ])
            ->all();
    }
}
