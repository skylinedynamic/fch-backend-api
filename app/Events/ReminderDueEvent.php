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
use App\Models\User\User;

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
        // A reminder created since reminders became per-user points
        // created_by/created_by_entity_type at the User itself, so it reaches only
        // its creator. Older rows identify the owning entity (Partner, Borrower,
        // Employee...), which several login accounts can share, so every user on
        // that entity still gets notified.
        $userIds = $this->reminder->created_by_entity_type === User::class
            ? collect([(int) $this->reminder->created_by])
            : User::where('entity_id', $this->reminder->created_by)
                ->where('entity_type', $this->reminder->created_by_entity_type)
                ->pluck('id');

        return $userIds
            ->map(fn ($id) => new PrivateChannel('App.Models.User.' . $id))
            ->all();
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
