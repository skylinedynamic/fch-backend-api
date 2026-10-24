<?php

namespace App\Models\Concerns;

use App\Rules\Ssn;
use Illuminate\Support\Facades\Crypt;

/**
 * A model that keeps a full SSN, encrypted, in its `ssn_encrypted` column and
 * exposes it as `ssn` - borrowers and leads alike.
 *
 * The decrypted number is not appended: a resource has to ask for `ssn`
 * explicitly, so the full number is never serialized by default.
 */
trait HasEncryptedSsn
{
    /** The stored SSN decrypted, or null when there is none (or it is unreadable). */
    public function getSsnAttribute(): ?string
    {
        if (!$this->ssn_encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($this->ssn_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Accepts an SSN with or without dashes and encrypts its digits into
     * ssn_encrypted - the only place the value is stored. Blank clears it.
     */
    public function setSsnAttribute(?string $value): void
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        $this->attributes['ssn_encrypted'] = $digits === ''
            ? null
            : Crypt::encryptString($digits);
    }

    /**
     * Whether the full nine-digit SSN is on file - not nothing, and not just
     * the last four digits rows from before the full SSN was collected hold.
     */
    public function hasFullSsn(): bool
    {
        return strlen((string) $this->ssn) === Ssn::digits();
    }
}
