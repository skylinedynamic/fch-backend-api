<?php

namespace App\Http\Resources\FCH\ActivityLog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Lead\Lead;
use Carbon\Carbon;

/** @mixin \App\Models\ActivityLog\ActivityLog */
class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $partnerImage = null;
        $borrowerImage = null;
        $leadImage = null;

        $subject = $this->relationLoaded('subject') ? $this->subject : null;

        // 1. Resolve Partner Image
        if ($subject instanceof Partner) {
            $partnerImage = $subject->partner_image ?? asset('avatar/avatar-default.jpg');
        } elseif ($this->partner_id) {
            // Check if subject belongs to this partner and relation is loaded
            if ($subject && isset($subject->partner_id) && (int)$subject->partner_id === (int)$this->partner_id && $subject->relationLoaded('partner')) {
                 $partnerImage = $subject->partner->partner_image ?? asset('avatar/avatar-default.jpg');
            } else {
                 $partner = Partner::find($this->partner_id);
                 $partnerImage = $partner ? ($partner->partner_image ?? asset('avatar/avatar-default.jpg')) : asset('avatar/avatar-default.jpg');
            }
        }

        // 2. Resolve Borrower/Client Image
        if ($subject instanceof Borrower) {
            $borrowerImage = $subject->image ?? asset('avatar/avatar-default.jpg');
        } elseif ($this->borrower_id) {
             $borrower = Borrower::find($this->borrower_id);
             $borrowerImage = $borrower ? ($borrower->image ?? asset('avatar/avatar-default.jpg')) : asset('avatar/avatar-default.jpg');
        }

        // 3. Resolve Lead Image
        if ($subject instanceof Lead) {
             $leadImage = asset('avatar/avatar-default.jpg'); 
        }

        return [
            'id'            => $this->id,
            'log_type_raw'  => $this->log_type,
            'log_type'      => $this->getEventLabel(), // Human-friendly title
            'partner_id'    => $this->partner_id,
            'partner_image' => $partnerImage,
            'borrower_id'   => $this->borrower_id,
            'borrower_image'=> $borrowerImage,
            'lead_image'    => $leadImage,
            // Activity fields
            'reference'     => $this->properties['reference'] ?? null,
            'category'      => $this->properties['category'] ?? null,
            'activity_date' => !empty($this->properties['activity_date']) ? Carbon::parse($this->properties['activity_date'])->format('Y-m-d') : null,
            'assigned_to'   => $this->properties['assigned_to'] ?? null,
            'assigned_user' => $this->whenLoaded('assignedUser', fn() => $this->assignedUser ? [
                'id'   => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
            ] : null),
            'description'   => $this->properties['description'] ?? ($this->getAutoDescription() ?? $this->description),
            // Email fields
            'to_emails'     => $this->properties['to_emails'] ?? null,
            'subject'           => $this->properties['subject'] ?? null,
            'attachments'       => $this->properties['attachments']['files'] ?? [],
            'attachments_count' => $this->properties['attachments']['count'] ?? 0,
            'status'            => $this->status,
            // Timeline specific
            'causer_name'   => ($this->relationLoaded('causer') && $this->causer) 
                                ? ($this->causer->name ?? 'System') 
                                : 'System',
            'causer_image'  => ($this->relationLoaded('causer') && $this->causer && method_exists($this->causer, 'getEntityImage')) 
                                ? $this->causer->getEntityImage() 
                                : ($borrowerImage ?? $partnerImage ?? asset('avatar/avatar-default.jpg')),
            'event_label'   => $this->getEventLabel(),
            'changes'       => $this->properties['attributes'] ?? null,
            'old_values'    => $this->properties['old'] ?? null,
            // Audit
            'causer'       => $this->whenLoaded('causer', fn() => $this->causer ? [
                'id'   => $this->causer->id,
                'name' => $this->causer->name,
            ] : null),
            'created_at'    => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at'    => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function getEventLabel(): string
    {
        // 1. Check for explicit event label in properties (Manual & System Event logged by Service)
        if (!empty($this->properties['event_label'])) {
            return $this->properties['event_label'];
        }

        // 2. Handle manual logs type check
        if ($this->log_type === 'email') return "Email Sent";
        if ($this->log_type === 'activity') return "Activity Created";
        if ($this->log_type === \App\Models\ActivityLog\ActivityLog::LOG_TYPE_EMAIL_UPDATE) return "Email Updated";

        // 3. Fallback for Spatie/Legacy logs - handle raw snake_case
        $cleanType = str_replace('_', ' ', $this->log_type ?? 'activity');
        $cleanType = trim(str_replace(['create', 'update', 'delete'], '', $cleanType));
        
        $desc = strtolower($this->description);
        
        // If description is a raw spatie action
        if ($desc === 'created') return ucfirst($cleanType) . " Created";
        if ($desc === 'updated') return ucfirst($cleanType) . " Updated";
        if ($desc === 'deleted') return ucfirst($cleanType) . " Deleted";

        // If description already has the info (from our new service logic)
        return ucfirst($this->description ?: $cleanType);
    }

    /**
     * Generate a nice description for auto-logged events if manual description is missing.
     */
    private function getAutoDescription(): ?string
    {
        $desc = strtolower($this->description);
        $name = str_replace('_', ' ', $this->log_type ?? 'activity');

        // If it's not a standard Spatie event, just return null or the description
        if (!in_array($desc, ['created', 'updated', 'deleted'])) {
            return null;
        }

        switch($desc) {
            case 'created': return "A new " . $name . " was created.";
            case 'updated': return ucfirst($name) . " info was updated.";
            case 'deleted': return ucfirst($name) . " record was deleted.";
            default:        return null;
        }
    }
}
