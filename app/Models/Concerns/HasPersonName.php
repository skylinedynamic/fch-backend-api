<?php

namespace App\Models\Concerns;

/**
 * One answer to "what is this person called".
 *
 * Before this, the same trim(first . ' ' . last) was written out in roughly forty
 * places across services, resources, mail and invoices. Adding a middle name meant
 * finding all of them, and the next change to how a name reads would mean finding
 * them again. Every model that stores a person's parts now composes the name here.
 *
 * Middle name is optional, so the join collapses the gap rather than leaving a
 * double space: "John Smith", not "John  Smith".
 */
trait HasPersonName
{
    /**
     * First, middle and last joined, skipping whatever is missing.
     *
     * Accepts the parts directly so callers holding loose values - a request array,
     * a co-borrower row from another table - get the identical result without
     * needing a model.
     */
    public static function composePersonName(?string $first, ?string $middle = null, ?string $last = null): string
    {
        return personName($first, $middle, $last);
    }

    /**
     * The person's name as it should be displayed.
     *
     * Named person_name rather than full_name on purpose: several of these tables
     * also have a real full_name COLUMN, and an accessor sharing that name would
     * shadow the stored value - a mistake already made once in this codebase.
     * Models with no such column may alias full_name to this.
     */
    public function getPersonNameAttribute(): string
    {
        return static::composePersonName(
            $this->first_name ?? null,
            $this->middle_name ?? null,
            $this->last_name ?? null
        );
    }
}
