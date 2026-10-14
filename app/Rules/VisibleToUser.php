<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;

/**
 * The id names a record the signed-in user may reach, by the model's own
 * visibleTo() scope - e.g. a funding application whose borrower belongs to the
 * caller's partner (or any, for the host's team). An `exists` rule only proves
 * the record is somewhere in the database.
 *
 *     'id' => ['nullable', new VisibleToUser(FundingApplication::class)]
 */
class VisibleToUser implements ValidationRule
{
    /** @param class-string<\Illuminate\Database\Eloquent\Model> $model */
    public function __construct(private string $model)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $visible = is_scalar($value)
            && $this->model::visibleTo(Auth::user())->whereKey($value)->exists();

        if (!$visible) {
            $fail(__('validation.exists', ['attribute' => str_replace('_', ' ', $attribute)]));
        }
    }
}
