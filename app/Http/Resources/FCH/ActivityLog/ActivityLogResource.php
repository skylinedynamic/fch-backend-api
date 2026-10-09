<?php

namespace App\Http\Resources\FCH\ActivityLog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Lead\Lead;
use App\Models\Common\Reminder;
use Carbon\Carbon;

/** @mixin \App\Models\ActivityLog\ActivityLog */
class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $timezone = $request->header('X-Timezone') ?? config('app.timezone');

        $partnerImage = null;
        $borrowerImage = null;
        $leadImage = null;

        $subject = $this->relationLoaded('subject') ? $this->subject : null;

        // 1. Resolve Partner Image
        if ($subject instanceof Partner) {
            $partnerImage = $subject->partner_image ?? asset('avatar/avatar-default.jpg');
        } elseif ($this->partner_id) {
            // Check if subject belongs to this partner and relation is loaded
            if ($subject && isset($subject->partner_id) && (int)$subject->partner_id === (int)$this->partner_id && $subject->relationLoaded('partner')) {
                 $partnerImage = $subject->partner->partner_image ?? asset('avatar/avatar-default.jpg');
            } else {
                 $partner = Partner::find($this->partner_id);
                 $partnerImage = $partner ? ($partner->partner_image ?? asset('avatar/avatar-default.jpg')) : asset('avatar/avatar-default.jpg');
            }
        }

        // 2. Resolve Borrower/Client Image
        if ($subject instanceof Borrower) {
            $borrowerImage = $subject->image ?? asset('avatar/avatar-default.jpg');
        } elseif ($this->borrower_id) {
             $borrower = Borrower::find($this->borrower_id);
             $borrowerImage = $borrower ? ($borrower->image ?? asset('avatar/avatar-default.jpg')) : asset('avatar/avatar-default.jpg');
        }

        // 3. Resolve Lead Image
        if ($subject instanceof Lead) {
             $leadImage = asset('avatar/avatar-default.jpg'); 
        }

        $props = $this->properties ?? [];

        // Rows flagged dates_utc store UTC and are converted to the viewer's timezone.
        // Older rows already hold the wall-clock value the user entered, so they go
        // through toTimezone() in the app timezone — formatted, but not shifted.
        $toDisplay = fn ($value) => empty($value)
            ? null
            : (!empty($props['dates_utc'])
                ? toTimezone($value, null, 'Y-m-d H:i:s')
                : toTimezone($value, config('app.timezone'), 'Y-m-d H:i:s'));

        $remindMe = (bool) ($props['remind_me'] ?? false);
        $reminderPreset = $props['reminder_preset'] ?? '30_minutes';
        $reminderTimeValue = $props['reminder_time_value'] ?? '30';
        $reminderTimeUnit = $props['reminder_time_unit'] ?? 'Minutes';

        $linkedReminder = !empty($props['reminder_id']) ? Reminder::find($props['reminder_id']) : null;
        if (!$linkedReminder && $this->id) {
            $linkedReminder = Reminder::where('activity_log_id', $this->id)->first();
        }
        if (!$linkedReminder && $this->subject_id && $this->subject_type && !empty($props['subject'])) {
            $linkedReminder = Reminder::where('entity_id', $this->subject_id)
                ->where('entity_type', $this->subject_type)
                ->where('summary', $props['subject'])
                ->latest()
                ->first();
        }

        $reminderStatus = null;
        $reminderStatusRaw = null;
        $isReminderMissed = false;

        if ($linkedReminder) {
            // The reminder row is the source of truth for when it actually fires
            $remindMe = true;
            $reminderStatusRaw = $linkedReminder->status;
            $remindAt = $linkedReminder->reminder_date_time
                ? toTimezone($linkedReminder->reminder_date_time, null, 'Y-m-d H:i:s')
                : null;

            $activityAt = $linkedReminder->set_date_time ?? $linkedReminder->send_at ?? $linkedReminder->reminder_date_time;
            $isReminderMissed = ($linkedReminder->status === Reminder::STATUS_PENDING || $linkedReminder->status === Reminder::STATUS_SENT)
                && $activityAt && Carbon::parse($activityAt)->isPast();

            $snoozeHistory = is_array($linkedReminder->snooze_history) ? $linkedReminder->snooze_history : [];
            $hasSnooze = !empty($snoozeHistory);
            $lastSnooze = $hasSnooze ? end($snoozeHistory) : null;
            $isCurrentlySnoozed = $hasSnooze && $linkedReminder->status === Reminder::STATUS_PENDING && !empty($lastSnooze['snoozed_until']) && Carbon::parse($lastSnooze['snoozed_until'])->isFuture();

            if ($isReminderMissed) {
                $reminderStatus = 'Missed';
            } elseif ($isCurrentlySnoozed) {
                $reminderStatus = 'Snoozed';
            } elseif ($linkedReminder->status === Reminder::STATUS_PENDING) {
                $reminderStatus = 'Pending';
            } elseif ($linkedReminder->status === Reminder::STATUS_SENT) {
                $reminderStatus = 'Sent';
            } elseif ($linkedReminder->status === Reminder::STATUS_CANCELLED) {
                $reminderStatus = 'Dismissed';
            } elseif ($linkedReminder->status === Reminder::STATUS_COMPLETED) {
                $reminderStatus = 'Completed';
            } else {
                $reminderStatus = ucfirst($linkedReminder->status);
            }

            if ($reminderPreset !== 'custom_date_time' && $linkedReminder->set_date_time && $linkedReminder->reminder_date_time) {
                $leadMinutes = (int) round(
                    ($linkedReminder->set_date_time->getTimestamp() - $linkedReminder->reminder_date_time->getTimestamp()) / 60
                );
                [$reminderPreset, $reminderTimeValue, $reminderTimeUnit] =
                    $this->resolveReminderPreset($leadMinutes, $reminderPreset, $reminderTimeValue, $reminderTimeUnit);
            }
        } elseif ($remindMe) {
            $reminderStatus = 'Pending';
            $reminderStatusRaw = Reminder::STATUS_PENDING;
            $remindAt = $toDisplay($props['remind_at'] ?? null);
        } else {
            $remindAt = $toDisplay($props['remind_at'] ?? null);
        }

        $isEdited = (bool) ($props['is_edited'] ?? false);

        return [
            'id'            => $this->id,
            'log_type_raw'  => $this->log_type,
            'log_type'      => $this->getEventLabel(), // Human-friendly title
            'partner_id'    => $this->partner_id,
            'partner_image' => $partnerImage,
            'borrower_id'   => $this->borrower_id,
            'borrower_image'=> $borrowerImage,
            'lead_image'    => $leadImage,
            // Activity fields
            'reference'     => $this->properties['reference'] ?? null,
            'category'      => $this->properties['category'] ?? null,
            'activity_date' => $toDisplay($props['activity_date'] ?? null),
            'assigned_to'   => $this->properties['assigned_to'] ?? null,
            'assigned_user' => $this->whenLoaded('assignedUser', fn() => $this->assignedUser ? [
                'id'   => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
            ] : null),
            'description'   => $this->properties['description'] ?? ($this->getAutoDescription() ?? $this->description),
            // Email fields
            'to_emails'     => $this->properties['to_emails'] ?? null,
            'subject'           => $this->properties['subject'] ?? null,
            'attachments'       => $this->properties['attachments']['files'] ?? [],
            'attachments_count' => $this->properties['attachments']['count'] ?? 0,
            'status'            => $this->status,
            // Visibility
            'visibility'          => $this->visibility,
            'visibility_label'    => \App\Models\ActivityLog\ActivityLog::VISIBILITY_LABELS[$this->visibility] ?? ucfirst((string) $this->visibility),
            'can_edit_visibility' => $this->resource->canChangeVisibilityBy($request->user()),
            // Reminder fields
            'remind_me'            => $remindMe,
            'remind_at'            => $remindAt,
            'reminder_id'          => $linkedReminder?->id ?? ($props['reminder_id'] ?? null),
            'reminder_status'      => $reminderStatus,
            'reminder_status_raw'  => $reminderStatusRaw,
            'is_reminder_missed'   => $isReminderMissed,
            'reminder_preset'      => $reminderPreset,
            'reminder_time_value'  => $reminderTimeValue,
            'reminder_time_unit'   => $reminderTimeUnit,
            // Timeline specific
            'causer_name'   => ($this->relationLoaded('causer') && $this->causer) 
                                ? ($this->causer->name ?? 'System') 
                                : 'System',
            'causer_image'  => ($this->relationLoaded('causer') && $this->causer && method_exists($this->causer, 'getEntityImage')) 
                                ? $this->causer->getEntityImage() 
                                : ($borrowerImage ?? $partnerImage ?? asset('avatar/avatar-default.jpg')),
            'event_label'   => $this->getEventLabel(),
            // On an "application edited" entry: each field that changed, with its
            // old and new value ({ field: { label, from, to } }). Sensitive ones
            // (SSN and the like) arrive as "[hidden]".
            'field_changes' => is_array($this->properties['changes'] ?? null) && ($this->properties['changes'] ?? []) !== []
                ? $this->properties['changes']
                : null,
            // On a submit / re-submit entry: the signature that was replaced
            // and the new one (image for an e-signature, typed name otherwise),
            // with their dates formatted through toTimezone().
            'signatures'    => (function () {
                $signatures = $this->properties['signatures'] ?? null;
                if (! is_array($signatures)) {
                    return null;
                }

                foreach ($signatures as $key => $signature) {
                    if (! is_array($signature)) {
                        unset($signatures[$key]);
                        continue;
                    }
                    if (! empty($signature['signed_at'])) {
                        $signatures[$key]['signed_at'] = toTimezone($signature['signed_at'], null, 'M d, Y g:i A');
                    }
                    if (! empty($signature['signature_date'])) {
                        $signatures[$key]['signature_date'] = toTimezone($signature['signature_date'], config('app.timezone'), 'M d, Y');
                    }
                }

                return $signatures ?: null;
            })(),
            'changes'       => $this->properties['attributes'] ?? null,
            'old_values'    => $this->properties['old'] ?? null,
            // Audit
            'causer'       => $this->whenLoaded('causer', fn() => $this->causer ? [
                'id'   => $this->causer->id,
                'name' => $this->causer->name,
            ] : null),
            'is_owner'      => $this->resource->isOwnedBy($request->user()),
            // An activity a person created, choosing its visibility themselves -
            // as opposed to an entry the system wrote (client created, invoice
            // created, application submitted ...), which nobody chose a level for.
            'is_manual'     => $this->resource->isManualActivity(),
            // Edit/delete are offered only on your own manual activity entries;
            // the API enforces the same rule.
            'can_edit'      => $this->resource->isEditableBy($request->user()),
            'can_delete'    => $this->resource->isEditableBy($request->user()),
            'is_edited'     => $isEdited,
            'edited_at'     => !empty($props['edited_at']) ? toTimezone($props['edited_at'], null, 'Y-m-d H:i:s') : toTimezone($this->updated_at, null, 'Y-m-d H:i:s'),
            'created_at'    => toTimezone($this->created_at, null, 'Y-m-d H:i:s'),
            'updated_at'    => toTimezone($this->updated_at, null, 'Y-m-d H:i:s'),
        ];
    }

    /**
     * Keep the saved "Reminder Time" option when it matches how far ahead the
     * reminder really fires; otherwise derive it from that lead time (older
     * activities were saved with the default 30_minutes regardless of choice).
     * Mirrors REMINDER_PRESETS / getReminderMsToSubtract in the admin frontend.
     */
    private function resolveReminderPreset(int $leadMinutes, string $preset, $value, $unit): array
    {
        $presetMinutes = [
            '5_minutes' => 5, '10_minutes' => 10, '15_minutes' => 15, '30_minutes' => 30,
            '1_hour' => 60, '1_day' => 1440, '2_days' => 2880, '3_days' => 4320,
            '1_week' => 10080, '2_weeks' => 20160, '3_weeks' => 30240, '1_month' => 43200,
        ];
        $unitMinutes = ['Minutes' => 1, 'Hours' => 60, 'Days' => 1440, 'Weeks' => 10080, 'Months' => 43200];

        if ($leadMinutes < 0) {
            return [$preset, $value, $unit];
        }
        if (($presetMinutes[$preset] ?? null) === $leadMinutes) {
            return [$preset, $value, $unit];
        }
        if ($preset === 'custom_time_before' && (int) $value * ($unitMinutes[$unit] ?? 0) === $leadMinutes) {
            return [$preset, $value, $unit];
        }
        if ($match = array_search($leadMinutes, $presetMinutes, true)) {
            return [$match, $value, $unit];
        }
        foreach (['Weeks' => 10080, 'Days' => 1440, 'Hours' => 60] as $u => $m) {
            if ($leadMinutes > 0 && $leadMinutes % $m === 0) {
                return ['custom_time_before', (string) intdiv($leadMinutes, $m), $u];
            }
        }

        return ['custom_time_before', (string) $leadMinutes, 'Minutes'];
    }

    private function getEventLabel(): string
    {
        // 1. Check for explicit event label in properties (Manual & System Event logged by Service)
        if (!empty($this->properties['event_label'])) {
            return $this->properties['event_label'];
        }

        // 2. Handle manual logs type check
        if ($this->log_type === 'email') return "Email Sent";
        if ($this->log_type === 'activity') return "Activity Created";
        if ($this->log_type === \App\Models\ActivityLog\ActivityLog::LOG_TYPE_EMAIL_UPDATE) return "Email Updated";

        // 3. Fallback for Spatie/Legacy logs - handle raw snake_case
        $cleanType = str_replace('_', ' ', $this->log_type ?? 'activity');
        $cleanType = trim(str_replace(['create', 'update', 'delete'], '', $cleanType));
        
        $desc = strtolower($this->description);
        
        // If description is a raw spatie action
        if ($desc === 'created') return ucfirst($cleanType) . " Created";
        if ($desc === 'updated') return ucfirst($cleanType) . " Updated";
        if ($desc === 'deleted') return ucfirst($cleanType) . " Deleted";

        // If description already has the info (from our new service logic)
        return ucfirst($this->description ?: $cleanType);
    }

    /**
     * Generate a nice description for auto-logged events if manual description is missing.
     */
    private function getAutoDescription(): ?string
    {
        $desc = strtolower($this->description);
        $name = str_replace('_', ' ', $this->log_type ?? 'activity');

        // If it's not a standard Spatie event, just return null or the description
        if (!in_array($desc, ['created', 'updated', 'deleted'])) {
            return null;
        }

        switch($desc) {
            case 'created': return "A new " . $name . " was created.";
            case 'updated': return ucfirst($name) . " info was updated.";
            case 'deleted': return ucfirst($name) . " record was deleted.";
            default:        return null;
        }
    }
}
