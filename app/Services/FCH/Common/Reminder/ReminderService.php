<?php

namespace App\Services\FCH\Common\Reminder;

use App\Jobs\Reminder\DispatchReminderDueJob;
use App\Models\Borrower\Borrower;
use App\Models\Common\Reminder;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class ReminderService
{
    /**
     * Get paginated list of reminders for a user.
     */
    public function getReminders(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = $filters['per_page'] ?? 15;
        $sortBy = $filters['sort_by'] ?? 'set_date_time';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $startDate = $filters['start_date'] ?? null;
        $endDate = $filters['end_date'] ?? null;
        $referenceType = $filters['reference_type'] ?? null;
        $status = $filters['status'] ?? null;
        $search = $filters['search'] ?? null;
        
        $validSortColumns = ['created_at', 'send_at', 'status', 'summary', 'set_date_time', 'reference'];
        if (!in_array($sortBy, $validSortColumns)) {
            $sortBy = 'set_date_time'; // Default to the Activity Date & Time
        }
        
        $query = Reminder::withClient()
                         ->where('created_by', $user->entity_id)
                         ->where('created_by_entity_type', $user->entity_type)
                         ->dateBetween($startDate, $endDate)
                         ->referenceType($referenceType)
                         ->statusFilter($status)
                         ->search($search);
            
        return $query->orderBy($sortBy, $sortOrder)->paginate($perPage);
    }

    /**
     * Get due reminders for a user.
     */
    public function getDueReminders(User $user): Collection
    {
        return Reminder::withClient()
            ->where('created_by', $user->entity_id)
            ->where('created_by_entity_type', $user->entity_type)
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->due();
                })->orWhere('status', Reminder::STATUS_SENT);
            })
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Dismiss a reminder.
     */
    public function dismissReminder(User $user, int $id): ?Reminder
    {
        $reminder = Reminder::where('id', $id)
            ->where('created_by', $user->entity_id)
            ->where('created_by_entity_type', $user->entity_type)
            ->first();

        if (!$reminder) {
            return null;
        }

        $reminder->update([
            'status' => Reminder::STATUS_CANCELLED
        ]);

        return $reminder;
    }

    /**
     * Bulk dismiss reminders.
     */
    public function bulkDismissReminders(User $user, array $ids): int
    {
        return Reminder::whereIn('id', $ids)
            ->where('created_by', $user->entity_id)
            ->where('created_by_entity_type', $user->entity_type)
            ->update([
                'status' => Reminder::STATUS_CANCELLED
            ]);
    }

    /**
     * Dismiss every actionable (pending or sent) reminder for a user — sent
     * reminders include ones already showing as "Missed" in the UI, so
     * restricting this to just STATUS_PENDING (as before) silently left
     * missed reminders undismissable by this bulk action, unlike the single-
     * reminder dismiss which has no such restriction.
     */
    public function dismissAllReminders(User $user): int
    {
        return Reminder::where('created_by', $user->entity_id)
            ->where('created_by_entity_type', $user->entity_type)
            ->whereIn('status', [Reminder::STATUS_PENDING, Reminder::STATUS_SENT])
            ->update([
                'status' => Reminder::STATUS_CANCELLED
            ]);
    }

    /**
     * Snooze a reminder by a relative number of minutes, or reschedule it to
     * an exact date/time (interpreted in the caller's timezone).
     */
    public function snoozeReminder(User $user, int $id, ?int $minutes = null, ?string $rescheduleAt = null, ?int $leadMinutes = null, ?string $note = null): ?Reminder
    {
        $reminder = Reminder::where('id', $id)
            ->where('created_by', $user->entity_id)
            ->where('created_by_entity_type', $user->entity_type)
            ->first();

        if (!$reminder) {
            return null;
        }

        if ($rescheduleAt) {
            $timezone = request()->header('X-Timezone', config('app.timezone'));
            $newActivityTime = Carbon::parse($rescheduleAt, $timezone)->setTimezone('UTC');

            if ($leadMinutes !== null) {
                // Caller explicitly picked "remind me X before" (matching the same
                // dropdown activity creation uses) — use that instead of whatever
                // this reminder's lead time happened to be before.
                $leadSeconds = max(0, $leadMinutes) * 60;
            } else {
                // Preserve however far in advance this reminder was originally set to
                // fire (e.g. "15 minutes before"), instead of collapsing the alert to
                // fire at the exact same moment as the rescheduled activity. Legacy/
                // malformed rows where the reminder was after the activity (lead < 0)
                // fall back to no lead time rather than pushing the alert out further.
                $leadSeconds = 0;
                if ($reminder->set_date_time && $reminder->reminder_date_time) {
                    $leadSeconds = max(0, $reminder->set_date_time->getTimestamp() - $reminder->reminder_date_time->getTimestamp());
                }
            }

            $newReminderTime = (clone $newActivityTime)->subSeconds($leadSeconds);

            // A reschedule moves the activity itself (the call/meeting), not just
            // when we alert about it — otherwise the activity stays stuck showing
            // as having happened in the past while the reminder fires long after.
            $reminder->set_date_time = $newActivityTime;
            $reminder->reminder_date_time = $newReminderTime;
            $reminder->send_at = $newReminderTime;
        } else {
            $newReminderTime = now()->addMinutes($minutes);
            $reminder->reminder_date_time = $newReminderTime;
            $reminder->send_at = $newReminderTime;
        }

        $note = is_string($note) ? trim($note) : null;
        $history = $reminder->snooze_history ?? [];
        $history[] = [
            'action' => $rescheduleAt ? 'rescheduled' : 'snoozed',
            'minutes' => $rescheduleAt ? null : $minutes,
            'note' => $note !== '' ? $note : null,
            'snoozed_at' => now()->utc()->toIso8601String(),
            'snoozed_until' => $newReminderTime->copy()->utc()->toIso8601String(),
            'snoozed_by' => $user->name,
        ];

        $reminder->snooze_history = $history;
        $reminder->is_reminder = true;
        $reminder->status = Reminder::STATUS_PENDING;
        $reminder->save();

        DispatchReminderDueJob::dispatch($reminder->id, $newReminderTime)->delay($newReminderTime);

        return $reminder->loadClient();
    }

    /**
     * Create a reminder from an activity log.
     */
    public function createFromActivity(
        array $data,
        $subjectId,
        $subjectType,
        $targetBorrowerId = null,
        $targetPartnerId = null,
        $attachmentFiles = []
    ): ?Reminder {
        $remindMe = filter_var($data['remind_me'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$remindMe || empty($data['remind_at'])) {
            return null;
        }

        // Convert remind_at to UTC using the user's timezone
        $timezone = request()->header('X-Timezone', config('app.timezone'));
        $remindAtUtc = Carbon::parse($data['remind_at'], $timezone)->setTimezone('UTC');
        
        $currentUser = auth()->user();

        // Convert activity_date to UTC using the user's timezone if it exists
        $referenceDateUtc = null;
        if (!empty($data['activity_date'])) {
            $referenceDateUtc = Carbon::parse($data['activity_date'], $timezone)->setTimezone('UTC');
        }

        $reminder = Reminder::create([
            'entity_id' => $subjectId,
            'entity_type' => $subjectType,
            'created_by' => $currentUser ? $currentUser->entity_id : null,
            'created_by_entity_type' => $currentUser ? $currentUser->entity_type : null,
            'reference' => $data['reference'] ?? null,
            'set_date_time' => $referenceDateUtc,
            'summary' => $data['subject'] ?? $data['event'] ?? null,
            'message' => $data['description'] ?? null,
            'is_reminder' => filter_var($data['is_reminder'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'reminder_date_time' => $remindAtUtc,
            'attachments' => $attachmentFiles,
            'status' => Reminder::STATUS_PENDING,
            'send_at' => $remindAtUtc,
        ]);

        DispatchReminderDueJob::dispatch($reminder->id, $remindAtUtc)->delay($remindAtUtc)->afterCommit();

        return $reminder;
    }

    /**
     * Stream a reminder attachment back to the browser.
     *
     * Attachment paths were written by different code paths over time
     * ("storage/attachments/x.pdf", "attachments/x.pdf", or a full URL), and a
     * reminder created from an activity log can also hold a stale copy of that
     * log's file list — so resolve the file against every shape before giving up.
     */
    public function downloadAttachment(int $reminderId, string $path)
    {
        $reminder = Reminder::find($reminderId);

        if (!$reminder) {
            throw new \Exception('Reminder not found', 404);
        }

        $attachment = $this->findAttachment($reminder, $path);

        if (!$attachment) {
            throw new \Exception('Attachment not found in this reminder', 404);
        }

        $filePath = (string) ($attachment['path'] ?? '');
        $fileName = $attachment['name'] ?? basename($filePath);
        $absPath = $this->resolveAttachmentFile($filePath);

        if (!$absPath) {
            throw new \Exception("File is no longer available on the server: {$fileName}", 404);
        }

        return response()->download($absPath, $fileName);
    }

    /**
     * Match the requested path against the reminder's own attachments, then
     * against the linked activity log's files (a reminder edited through the
     * activity form can lag behind the log's current file list).
     */
    private function findAttachment(Reminder $reminder, string $path): ?array
    {
        $sources = [
            $reminder->attachments ?? [],
            $reminder->activityLog->properties['attachments']['files'] ?? [],
        ];

        foreach ($sources as $attachments) {
            $match = collect($attachments)->first(function ($att) use ($path) {
                $attPath = $att['path'] ?? '';

                return $attPath === $path
                    || basename($attPath) === basename($path)
                    || ltrim(preg_replace('#^storage/#', '', $attPath), '/')
                        === ltrim(preg_replace('#^storage/#', '', $path), '/');
            });

            if ($match) {
                return (array) $match;
            }
        }

        return null;
    }

    /**
     * Absolute path of a stored attachment, or null when the file is gone.
     */
    private function resolveAttachmentFile(string $filePath): ?string
    {
        if ($filePath === '') {
            return null;
        }

        // A path saved as a full URL still points at this app's public disk.
        if (preg_match('#^https?://#i', $filePath)) {
            $filePath = ltrim((string) parse_url($filePath, PHP_URL_PATH), '/');
        }

        $relative = ltrim(preg_replace('#^storage/#', '', $filePath), '/');

        $candidates = [
            public_path('storage/' . $relative),
            storage_path('app/public/' . $relative),
            storage_path('app/' . $relative),
            // Older records kept only the file name.
            storage_path('app/public/attachments/' . basename($relative)),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
