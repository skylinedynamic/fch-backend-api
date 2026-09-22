<?php

namespace App\Mail\CreditBuilder;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use App\Models\Partner\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the host that a borrower has submitted credit builder tasks for review.
 *
 * Sent with the HOST's mail configuration, pinned in the constructor. The host
 * is both the recipient and the sender here: it is the platform telling itself
 * there is work waiting in the Verification Center. Leaving the partner to
 * getConfigPartner() would pick up the borrower's partner instead, since this is
 * raised while the borrower is the authenticated user.
 *
 * One message covers a whole submission. A borrower sending eight tasks at once
 * gets one email listing all eight with their tiers, not eight emails.
 */
class TaskVerificationRequestedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    protected array $dynamicData = [];

    /**
     * @param  array{borrower_name: string, borrower_email: ?string, borrower_id: ?int,
     *               partner_name: ?string, host_name: ?string,
     *               tasks: array<int, array{task_name: string, tier_name: string}>}  $mailData
     */
    /**
     * @param  ?int  $hostPartnerId  The host, looked up by the caller. Passed in
     *        rather than queried here because VerificationNotifier has already
     *        resolved it to find the recipient address - querying again would
     *        repeat the same lookup for the same row. Falls back to the query
     *        when constructed directly.
     */
    public function __construct(public array $mailData, ?int $hostPartnerId = null)
    {
        // The platform's own mailbox, never the submitting borrower's partner.
        $this->mailPartnerId = $hostPartnerId ?: Partner::where('is_host', 1)->value('id');
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        // Deep link to this borrower's requests, falling back to the Verification
        // Center itself when the id is missing. concatUrl() supplies the scheme -
        // BASE_PARTNER_PORTAL is stored without one, and a scheme-less href is
        // read as a relative path, which is what broke the button on the change
        // request mail.
        $borrowerId = $this->mailData['borrower_id'] ?? null;

        $this->dynamicData['reviewUrl'] = concatUrl(
            getPartnerDomain($this->mailPartnerId),
            $borrowerId
                ? 'verification-center/borrower-requests/' . $borrowerId
                : 'verification-center',
            config('app.frontend_partner_url')
        );

        $count = count($this->mailData['tasks'] ?? []);

        // The borrower's name and the task count go in the subject: whoever
        // works the queue can triage without opening anything.
        return new Envelope(
            subject: sprintf(
                '%s - %s (%s)',
                __('verification_center.new_request_subject'),
                $this->mailData['borrower_name'] ?? __('verification_center.default_borrower_name'),
                trans_choice('verification_center.task_count', $count, ['count' => $count])
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.credit_builder.verification_requested',
            with: [
                'siteName' => $this->dynamicData['siteName'],
                'logoUrl' => $this->dynamicData['logoUrl'],
                // The recipient - this mail goes to the host, so the greeting uses
                // the host's name. partnerName below is the BORROWER's partner,
                // which is a different party and belongs in the body.
                'hostName' => $this->mailData['host_name'] ?? __('borrower.host_partner'),
                'borrowerName' => $this->mailData['borrower_name'] ?? __('verification_center.default_borrower_name'),
                'borrowerEmail' => $this->mailData['borrower_email'] ?? null,
                'partnerName' => $this->mailData['partner_name'] ?? null,
                'tasks' => $this->mailData['tasks'] ?? [],
                'reviewUrl' => $this->dynamicData['reviewUrl'],
            ],
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
