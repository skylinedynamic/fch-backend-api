<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A list that shares no value with a sibling list - a lender rule's restricted
 * states against its allowed states, say. Both lists were validated only on
 * their own, so Texas could be saved as allowed and restricted at once and
 * which one applied to a Texas borrower was left undefined.
 *
 * The sibling is named by its last key segment and looked up next to the
 * attribute being validated: for "lender_visibilities.0.restricted_states" and
 * sibling "allowed_states" that is "lender_visibilities.0.allowed_states".
 */
class DisjointFrom implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param string $sibling     the other list's last key segment
     * @param string $messageKey  translation key; receives :values (the shared values)
     */
    public function __construct(private string $sibling, private string $messageKey)
    {
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_array($value) || $value === []) {
            return;
        }

        $siblingKey = preg_replace('/[^.]+$/', $this->sibling, $attribute);
        $other = data_get($this->data, $siblingKey);

        if (!is_array($other) || $other === []) {
            return;
        }

        $shared = array_values(array_intersect(
            array_map('strval', array_filter($value, 'is_scalar')),
            array_map('strval', array_filter($other, 'is_scalar'))
        ));

        if ($shared !== []) {
            $fail(__($this->messageKey, ['values' => implode(', ', array_unique($shared))]));
        }
    }
}
