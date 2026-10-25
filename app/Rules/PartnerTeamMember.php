<?php

namespace App\Rules;

use App\Models\Partner\Partner;
use App\Models\User\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The id names one of a partner's team (User::teamOf) - the users get-all-users
 * lists for that partner, e.g. who a client may be assigned to. An `exists`
 * rule only proves the user is somewhere on the platform.
 *
 * $keep is the record's value now: kept even when outside the team, so a
 * record saved before the rule still saves.
 *
 *     'assign_id' => ['nullable', 'integer', new PartnerTeamMember($partner, $borrower?->assign_id, $message)]
 */
class PartnerTeamMember implements ValidationRule
{
    public function __construct(private ?Partner $partner, private ?int $keep = null, private ?string $message = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->keep !== null && is_scalar($value) && (int) $value === $this->keep) {
            return;
        }

        $inTeam = $this->partner
            && is_scalar($value)
            && User::teamOf($this->partner)->whereKey($value)->exists();

        if (!$inTeam) {
            $fail($this->message ?? __('validation.exists', ['attribute' => str_replace('_', ' ', $attribute)]));
        }
    }
}
