<?php

namespace App\Rules;

use App\Models\Partner\Partner;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A portal domain nobody else has claimed (R3-BUG-020). Refused when it is:
 *  - one of the host's own domains: the website, the admin/partner portal,
 *    the client portal, the API, or a reserved name on the website domain;
 *  - any other partner's partner domain OR borrower domain (either column -
 *    the domain resolver matches both);
 *  - the same partner's other portal domain.
 *
 * $column is the field being checked ('partner_domain' or 'borrower_domain'),
 * used for the message; $otherDomain is the value sent for the other portal.
 */
class DomainNotClaimed implements ValidationRule
{
    public function __construct(
        private readonly string $column,
        private readonly ?int $exceptPartnerId = null,
        private readonly ?string $otherDomain = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }
        $domain = self::normalize($value);

        if ($this->otherDomain !== null && $domain === self::normalize($this->otherDomain)) {
            $fail(__("partner.validation.{$this->column}.same_as_partner"));
            return;
        }

        if (in_array($domain, self::hostDomains(), true)) {
            $fail(__("partner.validation.{$this->column}.unique"));
            return;
        }

        if (self::looksLikeBrand($domain)) {
            $fail(__('partner.validation.domain_lookalike'));
            return;
        }

        $taken = Partner::query()
            ->whereNull('deleted_at')
            ->when($this->exceptPartnerId, fn ($q) => $q->where('id', '!=', $this->exceptPartnerId))
            ->where(fn ($q) => $q->where('partner_domain', $domain)->orWhere('borrower_domain', $domain))
            ->exists();

        if ($taken) {
            $fail(__("partner.validation.{$this->column}.unique"));
        }
    }

    /**
     * The host's domains, as the resolver compares them: the same reserved list
     * the edge resolver uses (config/domains.php - website, API, partner and
     * client portals), the API's own URL, and the reserved names on the
     * website domain.
     *
     * @return array<int, string>
     */
    public static function hostDomains(): array
    {
        $base = self::normalize((string) config('domains.reserved.base_reserved_website', config('app.base_website')));

        $domains = array_map(
            fn ($domain) => self::normalize((string) $domain),
            array_values((array) config('domains.reserved', []))
        );
        $domains[] = $base;
        $domains[] = self::normalize((string) config('app.url'));
        foreach (Partner::RESTRICTED_DOMAINS as $name) {
            $domains[] = "{$name}.{$base}";
        }

        return array_values(array_unique(array_filter($domains)));
    }

    /**
     * Whether a domain uses or imitates a protected brand (R3-BUG-021): any
     * label below the TLD that contains the brand, or is within a letter or two
     * of it once hyphens are dropped and look-alike digits read as letters
     * ("f0urcornerfundng"). The platform's own subdomains (<label>.<base
     * website>) are not brand look-alikes - the claim checks cover those.
     */
    public static function looksLikeBrand(string $domain): bool
    {
        $base = self::normalize((string) config('domains.reserved.base_reserved_website', config('app.base_website')));
        if ($base !== '' && ($domain === $base || str_ends_with($domain, '.' . $base))) {
            return false;
        }

        $brands = array_filter(array_map(
            fn ($brand) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $brand)),
            (array) config('domains.protected_brands', [])
        ));
        if ($brands === []) {
            return false;
        }

        $labels = explode('.', $domain);
        array_pop($labels); // the TLD: ".co" is as much the brand as ".com"

        foreach ($labels as $label) {
            $plain = strtr(preg_replace('/[^a-z0-9]/', '', $label), ['0' => 'o', '1' => 'l', '3' => 'e', '5' => 's']);
            if ($plain === '') {
                continue;
            }
            foreach ($brands as $brand) {
                $allowed = strlen($brand) >= 10 ? 2 : 1;
                if (str_contains($plain, $brand) || levenshtein($plain, $brand) <= $allowed) {
                    return true;
                }
            }
        }

        return false;
    }

    /** No scheme, "www.", port, path or trailing slash; lower case. */
    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = preg_replace('#[/?\#].*$#', '', $value);
        $value = preg_replace('#:\d+$#', '', $value);
        $value = preg_replace('#^www\.#', '', $value);

        return rtrim($value, '.');
    }
}
