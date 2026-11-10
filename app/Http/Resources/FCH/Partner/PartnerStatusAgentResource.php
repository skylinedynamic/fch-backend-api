<?php

namespace App\Http\Resources\FCH\Partner;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A partner's status and agent, as the Status & Agent tab shows them. The
 * agent's email and phone may be empty: they are shown, never required.
 */
class PartnerStatusAgentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $agent = $this->assignedUser;

        return [
            'id'        => $this->id,
            'status'    => $this->status,
            'assign_to' => $this->assign_to !== null ? (int) $this->assign_to : null,
            'agent'     => $agent ? [
                'id'           => $agent->id,
                'name'         => $agent->name,
                'email'        => $agent->email,
                'phone_number' => $agent->phone_number,
            ] : null,
        ];
    }
}
