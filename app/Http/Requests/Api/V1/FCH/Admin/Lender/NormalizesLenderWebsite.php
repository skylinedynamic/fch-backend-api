<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Lender;

/**
 * Lender Website is checked as a URL (BUG-175). A site typed without a scheme
 * ("www.acme.com") gets "https://" first, so only real non-URLs are refused.
 */
trait NormalizesLenderWebsite
{
    protected function prepareForValidation(): void
    {
        $website = trim((string) $this->input('website', ''));

        if ($website !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $website)) {
            $website = 'https://'.$website;
        }

        if ($this->has('website')) {
            $this->merge(['website' => $website === '' ? null : $website]);
        }
    }
}
