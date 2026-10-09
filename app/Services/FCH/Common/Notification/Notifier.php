<?php

namespace App\Services\FCH\Common\Notification;

use App\Notifications\DynamicNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The one entry point for notifying anyone, on every channel:
 *
 *     app(Notifier::class)->send('borrower.payment.approved', $borrower, [
 *         'id' => $payment->id, 'amount' => '150.00',
 *     ], mail: (new PaymentApprovedMail($mailData, $partnerId))->to($payerEmail));
 *
 * Which channels a type uses lives in config/notifications.php (overridable
 * per key in the notification_types table), so a channel can be switched off
 * for a type without a deploy.
 *
 * In-app (the bell: `database`, `broadcast`) and `sms` go to the login users
 * behind $to, rendered from the type's templates, queued after the current DB
 * transaction commits. A failure here is logged and swallowed, so the bell can
 * never break the business action that triggered it.
 *
 * Mail is the Mailable the caller hands over, unchanged: its recipients come
 * from ->to(), its sending mailbox and branding from the Mailable itself
 * (MailConfig / MailableBranding). Whether it is queued or sent inline is the
 * Mailable's own declaration - ShouldQueue or SendsImmediately. The mail is not
 * routed through Laravel's notification mail channel because its recipients
 * are addresses (a partner's account email, a docs inbox, ops) rather than the
 * users the bell goes to. A mail failure reaches the caller exactly as a direct
 * Mail::send() would, since callers already handle - and sometimes act on -
 * that outcome.
 */
class Notifier
{
    public const CHANNEL_MAIL = 'mail';

    public function __construct(
        private readonly NotificationTypeRegistry $types,
        private readonly RecipientResolver $recipients,
        private readonly TemplateRenderer $renderer,
    ) {
    }

    /**
     * @param mixed         $to   Bell recipients: a User, an entity model (Borrower, Partner...), an array/collection of them, or null
     * @param array         $data Values for the type's {{placeholders}}
     * @param Mailable|null $mail The type's email, already addressed with ->to()
     * @return int Number of users notified in-app
     */
    public function send(string $typeKey, mixed $to = null, array $data = [], ?Mailable $mail = null): int
    {
        $type = $this->types->find($typeKey);

        if (!$type) {
            // A mistyped key costs the bell, never an email that went out before
            // the Notifier existed.
            Log::warning(__('notification.notifier.logs.unknown_type'), ['type' => $typeKey]);

            if ($mail) {
                $this->deliverMail($typeKey, $mail);
            }

            return 0;
        }

        if (!$type->isActive) {
            return 0;
        }

        $notified = $this->notifyInApp($type, $to, $data);

        if ($mail && in_array(self::CHANNEL_MAIL, $type->channels, true)) {
            $this->deliverMail($typeKey, $mail);
        }

        return $notified;
    }

    private function notifyInApp(NotificationTypeDefinition $type, mixed $to, array $data): int
    {
        $channels = array_values(array_intersect($type->channels, array_keys(config('notifications.channels', []))));

        if ($channels === [] || $to === null) {
            return 0;
        }

        try {
            $users = $this->recipients->resolve($to);

            if ($users->isEmpty()) {
                return 0;
            }

            if (!isset($data['name']) && $users->count() === 1) {
                $data['name'] = $users->first()->name ?? '';
            }

            $message = $this->renderer->render($type, $data);

            foreach ($users as $user) {
                $user->notify(new DynamicNotification($type->key, $message, $channels));
            }

            return $users->count();
        } catch (Throwable $e) {
            Log::error(__('notification.notifier.logs.in_app_failed'), [
                'type' => $type->key,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function deliverMail(string $typeKey, Mailable $mail): void
    {
        if (empty($mail->to)) {
            Log::warning(__('notification.notifier.logs.mail_no_recipient'), [
                'type' => $typeKey,
                'mailable' => $mail::class,
            ]);

            return;
        }

        if ($mail instanceof ShouldQueue && method_exists($mail, 'afterCommit')) {
            // Several callers send from inside a transaction. Queued before the
            // commit, a worker could run the job before the row it describes
            // exists, or after it was rolled back.
            $mail->afterCommit();
        }

        // Laravel queues a ShouldQueue Mailable and sends any other inline.
        Mail::send($mail);
    }
}
