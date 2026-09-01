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
        $timezone = $request->header('X-Timezone') ?? config('app.timezone');
        $displayDate = $this->reminder_date_time ?? $this->set_date_time ?? $this->send_at;

        return [
            'id' => $this->id,
            'summary' => $this->summary ?? 'Activity Due',
            'title' => $this->summary ?? 'Activity Due',
            'body' => $this->message ?? '',
            'url' => $this->url ?? '',
            'status' => $this->status,
            'send_at' => $this->send_at ? Carbon::parse($this->send_at)->setTimezone($timezone)->toIso8601String() : null,
            'remind_at' => $displayDate ? Carbon::parse($displayDate)->setTimezone($timezone)->toIso8601String() : null,
            'created_at' => $this->created_at ? Carbon::parse($this->created_at)->setTimezone($timezone)->toIso8601String() : null,
            // Detailed properties for modal UI
            'reference' => $this->reference ?? 'Meeting',
            'reference_type' => $this->reference ?? 'Meeting',
            'set_date_time' => $this->set_date_time ? Carbon::parse($this->set_date_time)->setTimezone($timezone)->toIso8601String() : null,
            'reminder_date_time' => $this->reminder_date_time ? Carbon::parse($this->reminder_date_time)->setTimezone($timezone)->toIso8601String() : null,
            'attachments' => $this->attachments ?? [],
            'message' => $this->message ?? ''
        ];
    }
}
