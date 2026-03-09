<?php

namespace App\Http\Resources\FCH\ActivityLog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ActivityLog\ActivityLog */
class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $partnerImage = null;
        if ($this->relationLoaded('partner') && $this->partner) {
            $partnerImage = $this->partner->partner_image 
                ?? asset('avatar/avater-profile.png');
        }

        $borrowerImage = null;
        if ($this->relationLoaded('borrower') && $this->borrower) {
            $borrowerImage = $this->borrower->business_logo 
                ? asset('storage/' . $this->borrower->business_logo) 
                : asset('avatar/avater-profile.png');
        }

        $leadImage = null;
        if ($this->entity_type === \App\Models\Lead\Lead::class && $this->entity_id) {
            $lead = \App\Models\Lead\Lead::find($this->entity_id);
            if ($lead) {
                $leadImage = asset('avatar/avater-profile.png');
            } else {
                $leadImage = asset('avatar/avater-profile.png');
            }
        }

        return [
            'id'            => $this->id,
            'log_type'      => $this->log_type,
            'partner_id'    => $this->partner_id,
            'partner_image' => $partnerImage,
            'borrower_id'   => $this->borrower_id,
            'borrower_image'=> $borrowerImage,
            'lead_image'    => $leadImage,
            // Activity fields
            'reference'     => $this->properties['reference'] ?? null,
            'category'      => $this->properties['category'] ?? null,
            'activity_date' => isset($this->properties['activity_date']) ? \Carbon\Carbon::parse($this->properties['activity_date'])->format('Y-m-d') : null,
            'assigned_to'   => $this->properties['assigned_to'] ?? null,
            'assigned_user' => $this->whenLoaded('assignedUser', fn() => $this->assignedUser ? [
                'id'   => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
            ] : null),
            'description'   => $this->properties['description'] ?? null,
            // Email fields
            'to_emails'     => $this->properties['to_emails'] ?? null,
            'subject'           => $this->properties['subject'] ?? null,
            'attachments'       => $this->properties['attachments']['files'] ?? [],
            'attachments_count' => $this->properties['attachments']['count'] ?? 0,
            'status'            => $this->status,
            'created_to'        => $this->created_to,
            'target_user'       => $this->whenLoaded('targetUser', fn() => $this->targetUser ? [
                'id'    => $this->targetUser->id,
                'name'  => $this->targetUser->name,
                'email' => $this->targetUser->email,
            ] : null),
            // Audit
            'created_by'    => $this->created_by,
            'creator'       => $this->whenLoaded('creator', fn() => $this->creator ? [
                'id'   => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'created_at'    => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at'    => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
