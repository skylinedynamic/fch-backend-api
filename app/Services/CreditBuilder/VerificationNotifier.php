<?php

namespace App\Services\CreditBuilder;

use App\Mail\CreditBuilder\TaskVerificationRequestedMail;
use App\Mail\Borrower\BorrowerVerificationStatusMail;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\Partner\Partner;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Who gets told about a credit builder verification, and whose mailbox it is
 * sent from.
 *
 * Those are two different questions, and they have different answers here:
 *
 *   borrower submits  -> the HOST is told,     sent from the HOST's mailbox
 *   decision reached  -> the BORROWER is told, sent from THEIR PARTNER's mailbox
 *
 * Nobody else is mailed. The partner is deliberately not copied on a decision.
 *
 * The rule behind the sender: a message goes out from the mailbox of whoever it
 * appears to come from. A borrower hears from their own partner, because that is
 * the only brand they know; the host hears from itself, because it is the
 * platform telling itself there is work waiting.
 *
 * "Their partner's mailbox" resolves correctly for both partner tiers without
 * anything special here. EmailConfigurationService::resolveFor() returns the
 * partner's own configuration when they have one - a White-Label partner - and
 * the host's when they do not, which is every Certified partner, since that tier
 * has no mail configuration of its own. Passing the borrower's partner id is
 * therefore the whole rule.
 *
 * This lives in one place because it previously did not: TaskVerificationService
 * and VerificationCenterService each carried their own copy of the status mail,
 * identical line for line, and two copies of a rule this fiddly will not stay
 * identical for long.
 */
class VerificationNotifier
{
    /**
     * Tell the host that a borrower has submitted tasks for verification.
     *
     * One message per submission, not per task: a borrower sending eight tasks
     * at once should produce one email listing all eight with their tiers, not
     * eight emails to the same inbox.
     *
     * @param  \Illuminate\Support\Collection<int, BorrowerTaskVerificationRequest>  $requests
     */
    public function requestSubmitted($borrower, $requests): void
    {
        try {
            if ($requests->isEmpty()) {
                return;
            }

            $host = $this->hostPartner();
            $recipient = $this->addressFor($host);

            if (!$recipient) {
                Log::warning(__('verification_center.log_host_email_missing'), [
                    'borrower_id' => $borrower?->id,
                ]);

                return;
            }

            Mail::to($recipient)->send(new TaskVerificationRequestedMail([
                'borrower_name' => $this->nameOf($borrower),
                'borrower_email' => $borrower?->email,
                // Lets the mail deep link straight to this borrower's requests
                // rather than the Verification Center's full list.
                'borrower_id' => $borrower?->id,
                // The recipient, for the greeting. Taken from the host row already
                // resolved above rather than looked up again in the mailable.
                'host_name' => $host?->full_name ?: $host?->company_name,
                'partner_name' => $borrower?->partner?->company_name ?: $borrower?->partner?->full_name,
                'tasks' => $this->taskLines($requests),
            ], $host?->id));
        } catch (\Throwable $e) {
            // The requests are already saved; failing to announce them must not
            // fail the borrower's submission.
            Log::error(__('verification_center.log_request_notify_failed'), [
                'borrower_id' => $borrower?->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tell the borrower what was decided.
     *
     * Covers both routes to a decision, manual and MicroBilt, because the
     * services funnel both into one consolidated result set before calling here.
     *
     * Only the borrower is mailed; the partner is not copied.
     *
     * @param  array<int, array{task_name: string, is_verified: bool, message: string, skipped?: bool}>  $taskResults
     */
    public function decisionMade($borrower, string $status, ?string $note, array $taskResults): void
    {
        // Skipped tasks were never actually reviewed - reporting them as an
        // outcome would be telling someone about something that did not happen.
        $results = array_values(array_filter($taskResults, fn ($r) => !($r['skipped'] ?? false)));

        if (empty($results)) {
            return;
        }

        // The fuller mapping from VerificationCenterService is kept: the admin
        // service only distinguished verified from rejected, which mislabelled a
        // request left in processing or pending as "Rejected".
        $statusLabel = match ($status) {
            BorrowerTaskVerificationRequest::COMPLETED => __('verification_center.verified'),
            BorrowerTaskVerificationRequest::REJECTED => __('verification_center.rejected'),
            BorrowerTaskVerificationRequest::PROCESSING => __('verification_center.processing'),
            BorrowerTaskVerificationRequest::PENDING => __('verification_center.pending'),
            default => ucfirst($status),
        };

        $this->notifyBorrower($borrower, $status, $statusLabel, $note, $results);
    }

    /**
     * The borrower, from their own partner's mailbox.
     */
    private function notifyBorrower($borrower, string $status, string $statusLabel, ?string $note, array $results): void
    {
        try {
            $email = $borrower?->email ?: $borrower?->user?->email;

            if (!$email) {
                return;
            }

            // The partner id is passed explicitly rather than left to
            // getConfigPartner(): this runs while an admin is authenticated, so
            // the ambient partner is the reviewer's, not the borrower's.
            Mail::to($email)->send(new BorrowerVerificationStatusMail([
                'subject' => __('verification_center.verification_status_update') . ' - ' . $statusLabel,
                'borrower_name' => $this->nameOf($borrower),
                'status' => $status,
                'note' => $note,
                'task_results' => $results,
            ], $borrower?->partner_id));
        } catch (\Throwable $e) {
            Log::error(__('verification_center.log_borrower_notify_failed'), [
                'borrower_id' => $borrower?->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Each submitted task with the tier it belongs to.
     *
     * The tier is read from the task itself rather than from the tier_id sent
     * with the request, so a submission spanning more than one tier is labelled
     * correctly rather than all under whichever tier the caller named.
     *
     * @return array<int, array{task_name: string, tier_name: string, tier_order: int|null}>
     */
    private function taskLines($requests): array
    {
        return $requests
            ->map(function (BorrowerTaskVerificationRequest $request) {
                $task = $request->relationLoaded('task') ? $request->task : $request->task()->first();
                $tier = $task?->tier ?: $request->tier()->first();

                return [
                    'task_name' => $task?->title ?: __('verification_center.unknown_task'),
                    'tier_name' => $tier?->name ?: __('verification_center.unknown_tier'),
                    'tier_order' => $tier?->order,
                ];
            })
            ->sortBy([['tier_order', 'asc'], ['task_name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * The platform's own partner row.
     *
     * A seam as much as a lookup: it is the one thing in this class that needs a
     * database, so a test can override it and exercise the notification rules
     * without one.
     */
    protected function hostPartner(): ?Partner
    {
        return Partner::where('is_host', 1)->first();
    }

    /**
     * Where a partner's mail goes.
     *
     * The account email, and only that. contact_person_email is deliberately
     * not used as a fallback: it is a different person - the named contact on
     * the account rather than the account itself - and silently redirecting
     * operational notifications there is not the same thing as sending them to
     * the partner. A partner with no account email is reported instead.
     *
     * The profile change request notifier follows the same rule, so both host
     * notifications land in one inbox.
     */
    private function addressFor(?Partner $partner): ?string
    {
        return $partner?->email ?: null;
    }

    private function nameOf($borrower): string
    {
        return personName(
            $borrower?->first_name ?? null,
            $borrower?->middle_name ?? null,
            $borrower?->last_name ?? null
        ) ?: __('verification_center.default_borrower_name');
    }
}
