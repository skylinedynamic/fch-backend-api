<?php

namespace App\Events;

use App\Http\Resources\FCH\Reminder\ReminderResource;
use App\Models\Common\Reminder;
use App\Models\User\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A reminder one of the user's pop-ups may be showing has changed.
 *
 *  - active: still due (edited while showing) - `reminder` is its new
 *    rendering, the same shape as ReminderDueEvent's, to replace the old one;
 *  - not active: dismissed, snoozed, rescheduled or deleted - `reminder` is
 *    null and the pop-up drops `id`.
 *
 * Lets every open tab and device stay in step without polling GET /due.
 */
class ReminderUpdatedEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $reminderId,
        public User $recipient,
        public ?Reminder $reminder = null,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.' . $this->recipient->id)];
    }

    public function broadcastWith(): array
    {
        $active = $this->reminder !== null;

        return [
            'id' => $this->reminderId,
            'active' => $active,
            'reminder' => $active
                ? ReminderResource::forRecipient($this->reminder->loadClient(), $this->recipient)->resolve()
                : null,
        ];
    }

    public function broadcastAs(): string
    {
        return 'reminder.updated';
    }
}
