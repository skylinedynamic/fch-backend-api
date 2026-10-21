<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Lead\Concerns;

use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Closure;

/**
 * The lead details rules that depend on whose lead it is: its owner is one of
 * that partner's team (User::teamOf), a non-host partner's lead is always a
 * Borrower lead, and a converted lead keeps its type.
 */
trait LeadPartnerRules
{
    /**
     * @param  Partner|null  $partner  the partner the lead is filed under
     * @param  int|null  $currentOwnerId  the lead's owner now - kept even when
     *                                    outside the team, so an older lead saves
     * @param  string|null  $lockedType  a converted lead's type - the only one it keeps
     */
    protected function leadPartnerRules(?Partner $partner, ?int $currentOwnerId = null, ?string $lockedType = null): array
    {
        $types = $partner?->is_host
            ? [Lead::PARTNER_TYPE_LEAD, Lead::BORROWER_TYPE_LEAD]
            : [Lead::BORROWER_TYPE_LEAD];

        return [
            'lead_type' => [
                'required',
                'string',
                'in:' . implode(',', $lockedType !== null ? [$lockedType] : $types),
            ],
            'salesperson_id' => [
                'nullable',
                function (string $attribute, mixed $value, Closure $fail) use ($partner, $currentOwnerId) {
                    if ($currentOwnerId !== null && (int) $value === $currentOwnerId) {
                        return;
                    }
                    if (!$partner || !User::teamOf($partner)->whereKey($value)->exists()) {
                        $fail(__('lead.salesperson_not_in_team'));
                    }
                },
            ],
        ];
    }

    protected function leadPartnerMessages(?Partner $partner, ?string $lockedType = null): array
    {
        if ($lockedType !== null) {
            return ['lead_type.in' => __('lead.lead_type_locked')];
        }

        return $partner?->is_host ? [] : ['lead_type.in' => __('lead.lead_type_borrower_only')];
    }
}
