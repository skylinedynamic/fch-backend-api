<?php

namespace App\Services\FCH\Partner;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Partner\Partner;
use App\Models\Partner\PartnerNotificationRecipient;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Partner profile › Mail Notification: reads and saves where each type of a
 * partner's notification emails goes (PartnerNotificationRecipient).
 */
class PartnerNotificationRecipientService
{
    public function __construct(private readonly ActivityLogService $activityLogService)
    {
    }

    /**
     * Every type, in order, with its label, help and saved address (null for
     * none - the partner's own email is used then).
     *
     * @return list<array{type: string, label: string, help: string, email: ?string}>
     */
    public function list(Partner $partner): array
    {
        $saved = $partner->notificationRecipients()->pluck('email', 'type');

        return array_map(fn (string $type) => [
            'type' => $type,
            'label' => __("partner.mail_notification.types.{$type}.label"),
            'help' => __("partner.mail_notification.types.{$type}.help"),
            'email' => $saved[$type] ?? null,
        ], PartnerNotificationRecipient::TYPES);
    }

    /**
     * Save the types sent: an address replaces that type's recipient, an
     * empty one removes it. Types not sent are left as they are. What changed
     * is logged on the partner's activity.
     *
     * @param array<string, ?string> $recipients type => address
     */
    public function sync(Partner $partner, array $recipients): void
    {
        $changes = DB::transaction(function () use ($partner, $recipients) {
            $before = $partner->notificationRecipients()->pluck('email', 'type');
            $changes = [];

            foreach ($recipients as $type => $email) {
                $email = trim((string) $email);
                $old = $before[$type] ?? null;
                $new = $email === '' ? null : $email;

                if ($new === null) {
                    $partner->notificationRecipients()->where('type', $type)->delete();
                } else {
                    $partner->notificationRecipients()->updateOrCreate(['type' => $type], ['email' => $new]);
                }

                if ($old !== $new) {
                    $changes[$type] = [
                        'label' => __("partner.mail_notification.types.{$type}.label"),
                        'from' => $old,
                        'to' => $new,
                    ];
                }
            }

            return $changes;
        });

        $partner->unsetRelation('notificationRecipients');
        $this->logChange($partner, $changes);
    }

    /**
     * "Mail notification updated" on the partner's activity, each changed
     * recipient old → new in its "Show changes" table. Host staff only
     * (internal). A failure is logged, never thrown: the recipients are saved.
     *
     * @param array<string, array{label: string, from: ?string, to: ?string}> $changes
     */
    private function logChange(Partner $partner, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        try {
            $this->activityLogService->logSystemEvent(
                Partner::class,
                $partner->id,
                $partner->id,
                null,
                __('partner.activity.mail_notification_updated'),
                __('partner.activity.mail_notification_updated_description', ['user' => Auth::user()?->name ?: 'The host']),
                ActivityLog::LOG_TYPE_PARTNER_MAIL_NOTIFICATION_UPDATE,
                ['changes' => $changes],
                ActivityLog::VISIBILITY_INTERNAL
            );
        } catch (Throwable $e) {
            Log::error('Failed to log a partner mail notification change', ['partner_id' => $partner->id, 'error' => $e->getMessage()]);
        }
    }
}
