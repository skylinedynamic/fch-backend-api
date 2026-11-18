<?php

namespace App\Rules;

use App\Models\Common\Role;
use App\Models\User\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The id names a role $user may give to someone (Role::assignableBy): one of
 * its own partner's, and the reserved Developer role only for a Developer.
 *
 *     'role_id' => ['required', new AssignableRole($this->user(), __('user.role_not_found'))]
 */
class AssignableRole implements ValidationRule
{
    public function __construct(private ?User $user, private ?string $message = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $assignable = $this->user
            && is_scalar($value)
            && Role::assignableBy($this->user)->whereKey($value)->exists();

        if (!$assignable) {
            $fail($this->message ?? __('validation.exists', ['attribute' => str_replace('_', ' ', $attribute)]));
        }
    }
}
