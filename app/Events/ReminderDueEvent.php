<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Common\Reminder;

class ReminderDueEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $reminder;
    public $activity;

    /**
     * Create a new event instance.
     */
    public function __construct(Reminder $reminder)
    {
        $this->reminder = $reminder;
        $this->activity = $reminder->entity;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.' . $this->reminder->created_by),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'reminder' => $this->reminder,
            'title' => 'Reminder: ' . ($this->reminder->summary ?? 'Activity Due'),
            'body' => $this->reminder->message ?? '',
            'url' => '/reminders',
        ];
    }
    
    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'reminder.due';
    }
}
