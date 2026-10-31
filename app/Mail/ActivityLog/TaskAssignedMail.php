<?php

namespace App\Mail\ActivityLog;

use App\Mail\Traits\MailableBranding;
use App\Mail\Traits\MailConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a staff member a Task activity was assigned to them: who assigned it,
 * on which record, when it is due, its priority and status, and a link to the
 * record in their own portal. With `changes` (and `updated_by`) it instead
 * tells the task's other person what was changed - the same task card below.
 *
 * Sent from the assignee's own partner's mailbox and branding - the partner
 * whose team they are on - and queued, so a slow mail server never holds up
 * saving the activity.
 */
class TaskAssignedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, MailableBranding, MailConfig;

    /** Badge colours per priority / status key, as the portal's tones. */
    private const PRIORITY_COLOURS = [
        'low' => ['#0ab39c', '#e6f7f5'],
        'medium' => ['#c88a04', '#fef4e4'],
        'high' => ['#f06548', '#fdeeeb'],
    ];

    private const STATUS_COLOURS = [
        'open' => ['#405189', '#eceef6'],
        'in_progress' => ['#299cdb', '#e9f5fb'],
        'complete' => ['#0ab39c', '#e6f7f5'],
    ];

    protected array $dynamicData = [];

    /**
     * @param  array{assignee_name: string, assigner_name: string, subject: string,
     *               description: ?string, record: string, due_date: ?string,
     *               priority: ?string, priority_label: ?string,
     *               status: ?string, status_label: ?string, path: string,
     *               updated_by?: string, changes?: string[]}  $mailData
     */
    public function __construct(public array $mailData, ?int $assigneePartnerId = null)
    {
        $this->setMailPartnerId($assigneePartnerId);
    }

    public function envelope(): Envelope
    {
        $branding = $this->getBrandingData();
        $this->dynamicData['siteName'] = $branding['siteName'];
        $this->dynamicData['logoUrl'] = $branding['logoUrl'];

        return new Envelope(
            subject: __(empty($this->mailData['changes']) ? 'activity_log.task_assigned_mail_subject' : 'activity_log.task_updated_mail_subject', ['subject' => $this->mailData['subject']]),
        );
    }

    public function content(): Content
    {
        $name = $this->mailData['assignee_name'];

        return new Content(
            view: 'emails.activity_log.task_assigned',
            with: [
                'siteName' => $this->dynamicData['siteName'] ?? null,
                'logoUrl' => $this->dynamicData['logoUrl'] ?? null,
                'task' => $this->mailData,
                'initials' => self::initials($name),
                'priorityColours' => self::PRIORITY_COLOURS[$this->mailData['priority'] ?? ''] ?? ['#495057', '#f3f3f9'],
                'statusColours' => self::STATUS_COLOURS[$this->mailData['status'] ?? ''] ?? ['#495057', '#f3f3f9'],
                'taskUrl' => concatUrl(
                    getPartnerDomain($this->mailPartnerId),
                    ltrim($this->mailData['path'], '/'),
                    config('app.frontend_partner_url')
                ),
            ],
        );
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];

        return mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    }
}
