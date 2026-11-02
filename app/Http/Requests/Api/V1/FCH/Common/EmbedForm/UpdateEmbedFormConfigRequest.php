<?php

namespace App\Http\Requests\Api\V1\FCH\Common\EmbedForm;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Support\EmbedForm\AllowedDomain;
use Closure;
use Illuminate\Validation\Validator;

/**
 * PUT /common/embed-forms/config
 *
 * Every field is optional: the configuration screen saves the whole form, but a
 * caller updating one setting should not have to resend the rest.
 */
class UpdateEmbedFormConfigRequest extends BaseApiRequest
{
    /** The Allowed Domains as typed, so a refusal names the entry the partner sees. */
    private array $typedDomains = [];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Each Allowed Domain is stored as its bare host - "http://host" when typed
     * with http:// (R2 BUG-035). An entry that names no host is left as typed
     * for the rules to refuse.
     */
    protected function prepareForValidation(): void
    {
        $domains = $this->input('allowed_domains');

        if (!is_array($domains)) {
            return;
        }

        $this->typedDomains = $domains;
        $this->merge([
            'allowed_domains' => array_map(
                fn($entry) => is_string($entry) ? (AllowedDomain::normalize($entry) ?? $entry) : $entry,
                $domains
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'form_title' => ['nullable', 'string', 'max:255'],
            'allowed_domains' => ['nullable', 'array'],
            'allowed_domains.*' => ['string', 'max:255', $this->domainRule()],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** The same domain twice is refused, however it was typed. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $domains = $this->input('allowed_domains');

            if (!is_array($domains) || $validator->errors()->isNotEmpty()) {
                return;
            }

            $seen = [];
            foreach ($domains as $index => $entry) {
                $host = AllowedDomain::host($entry);
                if (isset($seen[$host])) {
                    $validator->errors()->add(
                        "allowed_domains.{$index}",
                        __('embed_form.allowed_domain_duplicate', ['domain' => $this->typed($index, $entry)])
                    );
                }
                $seen[$host] = true;
            }
        });
    }

    /** A wildcard, localhost or anything that is not a domain is refused, naming the entry. */
    private function domainRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (!is_string($value)) {
                return;
            }

            $index = (int) substr($attribute, strrpos($attribute, '.') + 1);
            $typed = ['domain' => $this->typed($index, $value)];
            $host = AllowedDomain::host($value);

            if ($host !== null && AllowedDomain::isWildcard($host)) {
                $fail(__('embed_form.allowed_domain_wildcard', $typed));
            } elseif ($host !== null && AllowedDomain::isLocalhost($host)) {
                $fail(__('embed_form.allowed_domain_localhost', $typed));
            } elseif ($host === null || !AllowedDomain::isValidHost($host)) {
                $fail(__('embed_form.allowed_domain_invalid', $typed));
            }
        };
    }

    private function typed(int $index, string $fallback): string
    {
        $typed = $this->typedDomains[$index] ?? null;

        return is_string($typed) ? trim($typed) : $fallback;
    }
}
