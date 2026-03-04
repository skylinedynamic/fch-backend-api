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
            'type'          => $this->type,
            'partner_id'    => $this->partner_id,
            'borrower_id'   => $this->borrower_id,
            'lead_id'       => $this->lead_id,
            // Activity fields
            'reference'     => $this->reference,
            'category'      => $this->category,
            'activity_date' => $this->activity_date?->format('Y-m-d'),
            'assigned_to'   => $this->assigned_to,
            'assigned_user' => $this->whenLoaded('assignedUser', fn() => [
                'id'   => $this->assignedUser?->id,
                'name' => $this->assignedUser?->name,
            ]),
            'summary'       => $this->summary,
            'message_body'  => $this->message_body,
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
