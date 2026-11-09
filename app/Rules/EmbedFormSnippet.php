<?php

namespace App\Rules;

use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\Partner\Partner;
use App\Support\EmbedForm\EmbedFormLocation;
use App\Support\EmbedForm\EmbedSnippet;
use App\Support\EmbedForm\InvalidEmbedSnippet;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The host's own embed code, as copied from Embed Form Settings, for the
 * website's /apply page (CMS > Site Settings).
 *
 * Beyond EmbedSnippet's shape check, the iframe must open the host's current
 * form on its published address, and any script must be that address's
 * embed.js. An empty value passes: clearing the field returns /apply to its
 * default.
 */
class EmbedFormSnippet implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail(__('site_settings.apply_form_embed_code_invalid'));

            return;
        }

        try {
            $snippet = EmbedSnippet::parse($value);
        } catch (InvalidEmbedSnippet) {
            $fail(__('site_settings.apply_form_embed_code_invalid'));

            return;
        }

        $host = Partner::host();
        $location = EmbedFormLocation::forPartner($host);
        $key = $host ? EmbedFormConfig::where('partner_id', $host->id)->value('public_key') : null;

        if ($snippet->host() !== $location->host() || $snippet->key() !== $key) {
            $fail(__('site_settings.apply_form_embed_code_wrong_form'));

            return;
        }

        if ($snippet->scriptUrl() !== null && $snippet->scriptUrl() !== $location->embedScriptUrl()) {
            $fail(__('site_settings.apply_form_embed_code_wrong_script'));
        }
    }
}
