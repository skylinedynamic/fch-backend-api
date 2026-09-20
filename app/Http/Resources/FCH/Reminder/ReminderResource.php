<?php

namespace App\Http\Resources\FCH\Reminder;

use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class ReminderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $displayDate = $this->reminder_date_time ?? $this->set_date_time ?? $this->send_at;
        // Every date this resource returns goes through the toTimezone() helper in
        // the viewer's timezone (X-Timezone): ISO-8601 for logic, formatted for display.
        $toTz = fn ($value) => toTimezone($value, null, 'c');
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
                'snoozed_at_display' => toTimezone($entry['snoozed_at'] ?? null, null, 'F j, Y g:i A'),
                'snoozed_until_display' => toTimezone($entry['snoozed_until'] ?? null, null, 'F j, Y g:i A'),
            ]))
            ->values()
            ->all();

        return [
            'id' => $this->id,
            'activity_log_id' => $this->activity_log_id,
            // Editing a reminder means editing the activity behind it, which
            // only its creator may do — the same rule the activity list uses.
            'activity_can_edit' => (bool) $this->activityLog?->isEditableBy($request->user()),
            'client' => $client,
            'related' => $related,
            'snooze_history' => $snoozeHistory,
            'last_snooze_note' => collect($snoozeHistory)->reverse()->first(fn ($entry) => !empty($entry['note']))['note'] ?? null,
            'summary' => $this->summary ?? 'Activity Due',
            'title' => $this->summary ?? 'Activity Due',
            'body' => $this->message ?? '',
            'url' => $this->url ?? '',
            'status' => $this->status,
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
                'activity_date' => toTimezone($activityAt, null, 'F j, Y'),              // September 17, 2026
                'activity_time' => toTimezone($activityAt, null, 'g:i A'),               // 1:48 PM
                'activity_weekday' => toTimezone($activityAt, null, 'l'),                // Thursday
                'activity_weekday_short' => toTimezone($activityAt, null, 'D'),          // Thu
                'activity_day' => toTimezone($activityAt, null, 'j'),                    // 17
                'activity_month_year' => toTimezone($activityAt, null, 'M Y'),           // Sep 2026
                'activity_datetime' => toTimezone($activityAt, null, 'M j, Y, h:i A'),   // Sep 17, 2026, 01:48 PM
                'reminder_date' => toTimezone($remindAt, null, 'F j, Y'),                // September 17, 2026
                'reminder_date_short' => toTimezone($remindAt, null, 'M j, Y'),          // Sep 17, 2026
                'reminder_time' => toTimezone($remindAt, null, 'g:i A'),                 // 1:33 PM
            ],
        ];
    }
}
