<?php

namespace App\Jobs\Reminder;

use App\Services\FCH\Common\Reminder\ReminderBroadcaster;
use App\Models\Common\Reminder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Fires ReminderDueEvent (broadcast over Reverb) at the reminder's exact
 * due timestamp, via the queue's delayed-dispatch mechanism, instead of
 * waiting for the five-minute task:reminder-check sweep to notice it.
 */
class DispatchReminderDueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $reminderId,
        public Carbon $expectedDueAt,
    ) {
    }

    public function handle(): void
    {
        $reminder = Reminder::with(['entity', 'creator'])->find($this->reminderId);

        // A silent row (is_reminder false) is listed only, never popped up
        if (!$reminder || !$reminder->creator || $reminder->status !== Reminder::STATUS_PENDING || !$reminder->is_reminder) {
            return;
        }

        // If the reminder was snoozed/rescheduled to a later time since this job was
        // queued, a fresh job was scheduled for that new time — this stale one is a
        // no-op. But if reminder_date_time is now due (<= now), fire it regardless of
        // whether it exactly matches expectedDueAt: requiring exact equality made any
        // drift between the two (e.g. a partial update, or a slightly different value
        // written by a later call) silently and permanently drop the reminder, with no
        // error anywhere, since the fallback sweep only catches what this job misses.
        if (!$reminder->reminder_date_time || $reminder->reminder_date_time->isFuture()) {
            return;
        }

        // Atomically claim the reminder before broadcasting: if a dismiss (single or
        // bulk) flipped it to CANCELLED between the status check above and here, this
        // conditional update affects 0 rows. Without this guard, a plain ->update()
        // would blindly overwrite that CANCELLED back to SENT and we'd still fire the
        // broadcast, reopening the modal right after the user dismissed it.
        $claimed = Reminder::where('id', $reminder->id)
            ->where('status', Reminder::STATUS_PENDING)
            ->update(['status' => Reminder::STATUS_SENT]);

        if ($claimed === 0) {
            return;
        }

        $reminder->status = Reminder::STATUS_SENT;
        app(ReminderBroadcaster::class)->due($reminder);
    }
}
