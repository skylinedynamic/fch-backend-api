<?php

namespace App\Services\Profile;

use App\Mail\Borrower\ChangeRequestStatusMail;
use App\Mail\Partner\NewChangeRequestSubmittedMail;
use App\Models\Borrower\BorrowerChangeRequest;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Notification\Notifier;
use Illuminate\Support\Facades\Log;

/**
 * Who is told about a borrower's profile change request, and from whose mailbox.
 *
 * The same two rules the credit builder verification flow follows - see
 * App\Services\CreditBuilder\VerificationNotifier - because it is the same
 * shape of workflow: a borrower asks, the host decides.
 *
 *   borrower submits -> the HOST is told,     from the HOST's mailbox
 *   decision reached -> the BORROWER is told, from THEIR PARTNER's mailbox
 *
 * Nobody else is mailed; the partner is not copied on a decision.
 *
 * "Their partner's mailbox" needs no special handling for the partner tiers.
 * EmailConfigurationService::resolveFor() returns a partner's own configuration
 * when they have one - a White-Label partner - and the host's when they do not,
 * which is every Certified partner, since that tier has no mail configuration of
 * its own. Passing the borrower's partner id is the whole rule.
 *
 * Both messages are queued, and every send is wrapped: a mail failure is logged
 * and swallowed. The change request is already saved by the time this runs, and
 * a dead mail server must not surface as an error to the borrower submitting or
 * the reviewer deciding.
 */
class ChangeRequestNotifier
{
    /**
     * Tell the host a borrower has asked for a profile field to be changed.
     */
    public function requestSubmitted(BorrowerChangeRequest $changeRequest): void
    {
        try {
            $host = $this->hostPartner();
            $recipient = $this->addressFor($host);

            if (!$recipient) {
                // Still told in the bell; only the email has nowhere to go.
                Log::warning(__('profile.log_change_request_host_email_missing'), [
                    'change_request_id' => $changeRequest->id,
                ]);
            }

            app(Notifier::class)->send('host.change_request.submitted', $host, [
                'borrower' => $this->nameOf($changeRequest->borrower),
                'field' => $this->fieldOf($changeRequest),
            ], mail: $recipient ? (new NewChangeRequestSubmittedMail($host, $changeRequest))->to($recipient) : null);
        } catch (\Throwable $e) {
            Log::error(__('profile.log_change_request_notify_failed'), [
                'change_request_id' => $changeRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tell the borrower what the host decided about their request.
     */
    public function decisionMade(BorrowerChangeRequest $changeRequest): void
    {
        try {
            $borrower = $changeRequest->borrower;
            $email = $borrower?->email ?: $borrower?->business_email;

            if (!$email) {
                Log::warning(__('profile.log_change_request_borrower_email_missing'), [
                    'change_request_id' => $changeRequest->id,
                ]);
            }

            // The partner id is passed explicitly rather than left to
            // getConfigPartner(): a decision is made while an admin is
            // authenticated, so the ambient partner is the reviewer's, not the
            // borrower's. Without this the borrower's mail goes out through the
            // reviewing admin's mail server, under the wrong brand.
            //
            // The bell names the field but never the new value - that can be a
            // date of birth or an SSN, and the bell is stored and broadcast.
            app(Notifier::class)->send('borrower.change_request.decided', $borrower, [
                'field' => $this->fieldOf($changeRequest),
                'status' => strtolower((string) $changeRequest->status),
            ], mail: $email ? (new ChangeRequestStatusMail([
                'subject' => __('profile.change_request_status_subject'),
                'borrower_name' => $this->nameOf($borrower),
                'field_name' => $this->fieldOf($changeRequest),
                'status' => $changeRequest->status,
                // Masked for an SSN or EIN - email is not a place for either.
                'new_value' => $changeRequest->maskedValue($changeRequest->new_value),
                'note' => $changeRequest->note,
            ], $borrower?->partner_id))->to($email) : null);
        } catch (\Throwable $e) {
            Log::error(__('profile.log_change_request_status_notify_failed'), [
                'change_request_id' => $changeRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The platform's own partner row.
     *
     * A seam as much as a lookup: it is the one thing here that needs a
     * database, so a test can override it and exercise the rules without one.
     */
    protected function hostPartner(): ?Partner
    {
        return Partner::host();
    }

    /**
     * Where a partner's mail goes.
     *
     * The account email, and only that. contact_person_email is deliberately
     * not used as a fallback - it is a different person, the named contact on
     * the account rather than the account itself - so a partner with no account
     * email is reported rather than having their mail quietly redirected. This
     * flow did fall back to it before the send moved here.
     *
     * The credit builder verification notifier follows the same rule.
     */
    private function addressFor(?Partner $partner): ?string
    {
        return $partner?->email ?: null;
    }

    /** The label the borrower saw in the portal, not the column name. */
    private function fieldOf(BorrowerChangeRequest $changeRequest): ?string
    {
        return $changeRequest->visible_field_name ?: $changeRequest->field_name;
    }

    private function nameOf($borrower): string
    {
        return personName(
            $borrower?->first_name ?? null,
            $borrower?->middle_name ?? null,
            $borrower?->last_name ?? null
        ) ?: __('profile.default_borrower_name');
    }
}
