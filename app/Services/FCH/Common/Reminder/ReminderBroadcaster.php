<?php

namespace App\Services\FCH\Common\Reminder;

use App\Events\ReminderDueEvent;
use App\Events\ReminderUpdatedEvent;
use App\Models\Common\Reminder;

/**
 * Tells a reminder's users about it over Reverb, one event per user (each
 * rendered in that user's timezone). The admin panel fetches due reminders
 * once on page load and relies on these pushes for everything after that.
 */
class ReminderBroadcaster
{
    /** It has come due: open the pop-up with it. */
    public function due(Reminder $reminder): void
    {
        foreach ($reminder->recipients() as $user) {
            event(new ReminderDueEvent($reminder, $user));
        }
    }

    /** It changed: show its new details if it's still due, otherwise drop it. */
    public function updated(Reminder $reminder): void
    {
        if (!$reminder->isShowable()) {
            $this->gone($reminder);

            return;
        }

        foreach ($reminder->recipients() as $user) {
            event(new ReminderUpdatedEvent($reminder->id, $user, $reminder));
        }
    }

    /** Dismissed, snoozed, rescheduled or deleted: drop it from the pop-up. */
    public function gone(Reminder $reminder): void
    {
        foreach ($reminder->recipients() as $user) {
            event(new ReminderUpdatedEvent($reminder->id, $user));
        }
    }
}
