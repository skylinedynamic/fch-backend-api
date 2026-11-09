<?php

namespace App\Http\Resources\FCH\Common\Audit;

use App\Support\Audit\AuditLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\Audit\FundingApplicationAuditTrail;

/** One event of a funding application's audit trail. Times are UTC (ISO 8601). */
class FundingApplicationAuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'event_label' => AuditLabels::event($this->event),
            'occurred_at' => $this->occurred_at?->utc()->toIso8601ZuluString(),
            'actor' => [
                'id' => $this->actor_user_id,
                'name' => $this->actor_name,
                'type' => $this->actor_type,
            ],
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'changes' => array_map(fn (array $change) => $change + ['label' => AuditLabels::field($change['field'])], $this->changes ?? []),
            'context' => $this->context ?? (object) [],
            'file' => $this->file_hash ? [
                'sha256' => $this->file_hash,
                // Only an event whose file is in the audit folder can hand it out.
                'downloadable' => app(FundingApplicationAuditTrail::class)->storedFile($this->resource) !== null,
                'label' => isset($this->context['file_type']) ? AuditLabels::file($this->context['file_type']) : null,
            ] : null,
            'is_legacy' => (bool) $this->is_legacy,
            'hash' => $this->hash,
        ];
    }
}
