<?php

namespace App\Models\CreditAuthorization;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerCreditProfile;
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

    /** How the portal shows these times, e.g. "Sep 16, 2026 02:30 AM". */
    public const DISPLAY_FORMAT = 'M d, Y h:i A';

    /** Where a client's credit request stands: waiting for a pull, or pulled. */
    public const REQUEST_PENDING = 'pending';
    public const REQUEST_COMPLETED = 'completed';

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
                // In the viewer's time zone (X-Timezone) for the portal. The
                // stored values, and the PDF built from them, stay UTC.
                'consented_at' => toTimezone($consent->consented_at, null, self::DISPLAY_FORMAT),
                'ip_address' => $consent->ip_address,
                'user_agent' => $consent->user_agent,
                'isoftpull_transaction_id' => $consent->isoftpull_transaction_id,
                'pulled_at' => toTimezone($consent->pulled_at, null, self::DISPLAY_FORMAT),
            ])
            ->all();
    }

    /**
     * When this borrower's credit was last pulled from iSoftPull, in the
     * viewer's time zone. Worked out here rather than from the formatted rows:
     * "Sep 16, 2026 02:30 AM" does not sort as text.
     */
    public static function lastPulledForBorrower(int $borrowerId): ?string
    {
        $latest = static::where('borrower_id', $borrowerId)->max('pulled_at');

        return toTimezone($latest, null, self::DISPLAY_FORMAT);
    }

    /**
     * Where a client's credit pulls stand, for the client's own portal.
     *
     * A request is the consent the client gave. It is "pending" until a pull is
     * attached to it (`pulled_at`), "completed" after. The latest request decides
     * the status; the last pull is the latest `pulled_at` across all of them, so
     * a new request still waiting does not hide the pull before it.
     *
     * Times are in the viewer's time zone (X-Timezone), already formatted.
     *
     * @return array{last_pulled_at: ?string, last_request: ?array{status: string, requested_at: ?string, pulled_at: ?string}}
     */
    public static function pullStatusForBorrower(int $borrowerId): array
    {
        $latest = static::where('borrower_id', $borrowerId)
            ->orderByDesc('consented_at')
            ->orderByDesc('id')
            ->first();

        return [
            'last_pulled_at' => static::lastPulledForBorrower($borrowerId),
            'last_request' => $latest ? [
                'status' => $latest->pulled_at ? self::REQUEST_COMPLETED : self::REQUEST_PENDING,
                'requested_at' => toTimezone($latest->consented_at, null, self::DISPLAY_FORMAT),
                'pulled_at' => toTimezone($latest->pulled_at, null, self::DISPLAY_FORMAT),
            ] : null,
        ];
    }

    /**
     * Whether each client's credit request is still waiting, for the admin list.
     *
     * The client's latest consent decides: "pending" until a pull is attached
     * to it, "completed" after. A request from before consents were recorded
     * has none, so it falls back to whether a credit profile has been saved for
     * the client - that is the pull having run.
     *
     * Two queries for the whole page rather than two per row.
     *
     * @param  array<int, int>  $borrowerIds
     * @return array<int, string>  'pending' | 'completed', keyed by borrower id
     */
    public static function requestStatusesForBorrowers(array $borrowerIds): array
    {
        $borrowerIds = array_values(array_unique(array_filter($borrowerIds)));
        if ($borrowerIds === []) {
            return [];
        }

        $latestPulled = static::whereIn('borrower_id', $borrowerIds)
            ->orderByDesc('consented_at')
            ->orderByDesc('id')
            ->get(['borrower_id', 'pulled_at'])
            ->unique('borrower_id')
            ->mapWithKeys(fn (self $consent) => [$consent->borrower_id => $consent->pulled_at !== null]);

        $withProfile = BorrowerCreditProfile::whereIn('borrower_id', $borrowerIds)
            ->pluck('borrower_id')
            ->flip();

        $statuses = [];
        foreach ($borrowerIds as $borrowerId) {
            $pulled = $latestPulled->has($borrowerId)
                ? $latestPulled->get($borrowerId)
                : $withProfile->has($borrowerId);

            $statuses[$borrowerId] = $pulled ? self::REQUEST_COMPLETED : self::REQUEST_PENDING;
        }

        return $statuses;
    }
}
