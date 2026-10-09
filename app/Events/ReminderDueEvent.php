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
 * A reminder has come due: pushed to one of its users so their pop-up opens.
 *
 * One event per recipient (see ReminderBroadcaster::due()) because the payload
 * is the reminder rendered for that user - their timezone, their edit rights -
 * exactly as their GET /reminders/due would return it, so the browser shows
 * it straight from the push without calling the API.
 */
class ReminderDueEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Reminder $reminder,
        public User $recipient,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.' . $this->recipient->id)];
    }

    public function broadcastWith(): array
    {
        $reminder = $this->reminder->loadClient();

        return [
            'reminder' => ReminderResource::forRecipient($reminder, $this->recipient)->resolve(),
            'title' => 'Reminder: ' . ($reminder->summary ?? 'Activity Due'),
            'body' => $reminder->message ?? '',
            'url' => '/reminders',
        ];
    }

    public function broadcastAs(): string
    {
        return 'reminder.due';
    }
}
