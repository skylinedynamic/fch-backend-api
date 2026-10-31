<?php

namespace App\Http\Resources\FCH\Reminder;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Common\Reminder;
use App\Models\User\User;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class ReminderResource extends JsonResource
{
    /** Who the reminder is rendered for when there's no request (a Reverb push). */
    protected ?User $recipient = null;

    /** That user's timezone; null means the request's X-Timezone, as before. */
    protected ?string $recipientTimezone = null;

    /**
     * The reminder exactly as $user's own GET /reminders/due would return it:
     * dates in their stored timezone (or the app's when it isn't known yet) and
     * edit rights worked out for them.
     */
    public static function forRecipient(Reminder $reminder, User $user): static
    {
        $resource = new static($reminder);
        $resource->recipient = $user;
        $resource->recipientTimezone = $user->timezone ?: config('app.timezone', 'UTC');

        return $resource;
    }

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $tz = $this->recipientTimezone;
        $viewer = $this->recipient ?? $request?->user();
        $displayDate = $this->reminder_date_time ?? $this->set_date_time ?? $this->send_at;
        // Every date this resource returns goes through the toTimezone() helper in
        // the viewer's timezone (X-Timezone): ISO-8601 for logic, formatted for display.
        $toTz = fn ($value) => toTimezone($value, $tz, 'c');
        ['client' => $client, 'related' => $related] = $this->clientSummary();

        // The activity itself (the call/meeting) and the moment its reminder
        // fires. Every date the reminder screens show comes from these, already
        // converted to the viewer's timezone and formatted by toTimezone(), so
        // the frontend displays them as-is instead of formatting dates itself.
        $activityAt = $this->set_date_time ?? $this->send_at ?? $this->reminder_date_time;
        $remindAt = $this->reminder_date_time ?? $this->send_at ?? $this->set_date_time;

        $snoozeHistory = collect($this->snooze_history ?? [])
            ->map(fn ($entry) => array_merge($entry, [
                'snoozed_at' => $toTz($entry['snoozed_at'] ?? null),
                'snoozed_until' => $toTz($entry['snoozed_until'] ?? null),
                'snoozed_at_display' => toTimezone($entry['snoozed_at'] ?? null, $tz, 'F j, Y g:i A'),
                'snoozed_until_display' => toTimezone($entry['snoozed_until'] ?? null, $tz, 'F j, Y g:i A'),
            ]))
            ->values()
            ->all();

        return [
            'id' => $this->id,
            'activity_log_id' => $this->activity_log_id,
            // Editing a reminder means editing the activity behind it, which
            // only its creator may do — the same rule the activity list uses.
            'activity_can_edit' => (bool) ($viewer && $this->activityLog?->isEditableBy($viewer)),
            // An assigned Task behind the reminder, and whether this viewer (its
            // creator or assignee) may change its status from the reminder.
            'task' => $this->taskSummary($tz),
            'task_can_update_status' => (bool) ($viewer && $this->activityLog?->canUpdateTaskStatusBy($viewer)),
            // An assigned Task's date is its creator's to move; the assignee only snoozes or dismisses
            'can_reschedule' => !$this->activityLog || ($viewer && $this->activityLog->canRescheduleBy($viewer)),
            'client' => $client,
            'related' => $related,
            'snooze_history' => $snoozeHistory,
            'last_snooze_note' => collect($snoozeHistory)->reverse()->first(fn ($entry) => !empty($entry['note']))['note'] ?? null,
            'summary' => $this->summary ?? 'Activity Due',
            'title' => $this->summary ?? 'Activity Due',
            'body' => $this->message ?? '',
            'url' => $this->url ?? '',
            'status' => $this->status,
            // false: listed only, never pops up (the creator's copy of a task only its assignee is reminded of)
            'is_reminder' => (bool) ($this->is_reminder ?? true),
            'send_at' => $toTz($this->send_at),
            'remind_at' => $toTz($displayDate),
            'created_at' => $toTz($this->created_at),
            // Detailed properties for modal UI
            'reference' => $this->reference ?? 'Meeting',
            'reference_type' => $this->reference ?? 'Meeting',
            'set_date_time' => $toTz($this->set_date_time),
            'reminder_date_time' => $toTz($this->reminder_date_time),
            'attachments' => $this->attachments ?? [],
            'message' => $this->message ?? '',
            // Whether the activity's own time has passed ("missed" once the
            // reminder is still pending/sent) — decided here, on the server clock.
            'is_past_due' => $activityAt ? Carbon::parse($activityAt)->isPast() : false,
            'display' => [
                'activity_date' => toTimezone($activityAt, $tz, 'F j, Y'),              // September 17, 2026
                'activity_time' => toTimezone($activityAt, $tz, 'g:i A'),               // 1:48 PM
                'activity_weekday' => toTimezone($activityAt, $tz, 'l'),                // Thursday
                'activity_weekday_short' => toTimezone($activityAt, $tz, 'D'),          // Thu
                'activity_day' => toTimezone($activityAt, $tz, 'j'),                    // 17
                'activity_month_year' => toTimezone($activityAt, $tz, 'M Y'),           // Sep 2026
                'activity_datetime' => toTimezone($activityAt, $tz, 'M j, Y, h:i A'),   // Sep 17, 2026, 01:48 PM
                'reminder_date' => toTimezone($remindAt, $tz, 'F j, Y'),                // September 17, 2026
                'reminder_date_short' => toTimezone($remindAt, $tz, 'M j, Y'),          // Sep 17, 2026
                'reminder_time' => toTimezone($remindAt, $tz, 'g:i A'),                 // 1:33 PM
            ],
        ];
    }

    /** The assigned Task behind this reminder, as the reminder screens show it (null when there is none). */
    private function taskSummary(?string $tz): ?array
    {
        $log = $this->activityLog;
        if (!$log || !$log->isAssignedTask()) {
            return null;
        }

        $task = collect($log->properties)->get('task');
        $status = $task['status'] ?? null;
        $priority = $task['priority'] ?? null;
        $dueLocal = ActivityLog::taskDueDateLocal($task['due_date'] ?? null, $tz);

        return [
            'status' => $status,
            'status_label' => ActivityLog::TASK_STATUS_LABELS[$status] ?? null,
            'priority' => $priority,
            'priority_label' => ActivityLog::TASK_PRIORITY_LABELS[$priority] ?? null,
            'due_date' => $dueLocal,
            'due_date_display' => $dueLocal ? Carbon::parse($dueLocal)->format('M j, Y g:i A') : null,
            'assigned_to_name' => User::query()->whereKey($task['assigned_to'])->value('name'),
            'assigned_by_name' => $log->causer?->name,
        ];
    }
}
