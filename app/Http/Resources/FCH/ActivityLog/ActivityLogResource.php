<?php

namespace App\Http\Resources\FCH\ActivityLog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ActivityLog\ActivityLog */
class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'log_name'      => $this->log_name,
            'partner_id'    => $this->partner_id,
            'borrower_id'   => $this->borrower_id,
            // Activity fields
            'reference'     => $this->reference,
            'category'      => $this->category,
            'activity_date' => $this->activity_date?->format('Y-m-d'),
            'assigned_to'   => $this->assigned_to,
            'assigned_user' => $this->whenLoaded('assignedUser', fn() => [
                'id'   => $this->assignedUser?->id,
                'name' => $this->assignedUser?->name,
            ]),
            'description'   => $this->description,
            // Email fields
            'to_emails'     => $this->to_emails,
            'subject'       => $this->subject,
            'attachments'   => $this->attachments,
            'status'        => $this->status,
            // Audit
            'created_by'    => $this->created_by,
            'creator'       => $this->whenLoaded('creator', fn() => [
                'id'   => $this->creator?->id,
                'name' => $this->creator?->name,
            ]),
            'created_at'    => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at'    => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
