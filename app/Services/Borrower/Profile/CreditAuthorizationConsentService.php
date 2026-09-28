<?php

namespace App\Services\Borrower\Profile;

use App\Models\CreditAuthorization\CreditAuthorizationConsent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Writes the evidence that a client authorised a credit pull.
 *
 * Everything a later audit needs is captured here at the moment of consent -
 * the wording, its version, who, when, from where - because none of it can be
 * reconstructed afterwards. The request is passed in rather than read from the
 * container so the IP and user agent belong to the client's own call and not to
 * whatever is running later.
 */
class CreditAuthorizationConsentService
{
    /**
     * Record one client's agreement, exactly as they were shown it.
     *
     * The caller has already rejected the request if either box was unticked,
     * so both are recorded as accepted - a row here only ever describes a
     * complete consent.
     */
    public function record(
        int $borrowerId,
        Request $request,
        ?int $creditProfileRequestId = null
    ): CreditAuthorizationConsent {
        return CreditAuthorizationConsent::create([
            'borrower_id' => $borrowerId,
            'credit_profile_request_id' => $creditProfileRequestId,

            // Copied in, not referenced: the published wording gains new
            // versions rather than edits, and this row has to keep showing what
            // this client saw.
            'authorization_version' => (string) config('credit_authorization.version'),
            'authorization_url' => (string) config('credit_authorization.authorization_url'),
            'credit_authorization_text' => (string) config('credit_authorization.credit_authorization_text'),
            'e_consent_text' => (string) config('credit_authorization.e_consent_text'),

            'credit_authorization_accepted' => true,
            'e_consent_accepted' => true,

            // UTC, and never rewritten afterwards.
            'consented_at' => Carbon::now('UTC'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * Tie a consent to the pull it authorised, once that pull has run.
     *
     * Only ever adds: `consented_at` is the fact being proved and moving it to
     * the pull's time would destroy it.
     */
    public function attachPull(
        CreditAuthorizationConsent $consent,
        ?string $isoftpullTransactionId = null,
        ?Carbon $pulledAt = null
    ): CreditAuthorizationConsent {
        $consent->forceFill([
            'isoftpull_transaction_id' => $isoftpullTransactionId,
            'pulled_at' => $pulledAt ?: Carbon::now('UTC'),
        ])->save();

        return $consent;
    }

    /**
     * The consent a pull should be attributed to: this borrower's most recent
     * one that is not already tied to a pull.
     *
     * Returns null when there is none, which is itself the answer an audit
     * needs - the pull was not authorised and that must not be papered over.
     */
    public function latestUnattached(int $borrowerId): ?CreditAuthorizationConsent
    {
        return CreditAuthorizationConsent::where('borrower_id', $borrowerId)
            ->whereNull('isoftpull_transaction_id')
            ->orderByDesc('consented_at')
            ->orderByDesc('id')
            ->first();
    }
}
