<?php

namespace App\Rules;

use App\Models\Common\Role;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A role's name may not be the reserved Developer role's - in any case or
 * padding - so no other role can pass for it (or take its code on create).
 *
 *     'name' => ['required', 'string', new NotReservedRoleName()]
 */
class NotReservedRoleName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && Role::isReservedName($value)) {
            $fail(__('role_permission.name_reserved'));
        }
    }
}
