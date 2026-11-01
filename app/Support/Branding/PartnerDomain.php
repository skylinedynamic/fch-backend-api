<?php

namespace App\Support\Branding;

use App\Models\Partner\Partner;
use Illuminate\Support\Facades\Cache;

/**
 * Which partner a portal domain belongs to.
 *
 * Branding looks the domain up on every cold page load, so the answer is
 * cached for an hour. The cache is cleared whenever a partner's domains change
 * and whenever a partner is deleted or restored (Partner::booted). Until
 * BUG-118 it was never cleared: a domain taken over from a deleted partner
 * resolved to no partner, and its login page showed an empty company name -
 * "(c) 2026 . All rights reserved." - for up to an hour.
 */
final class PartnerDomain
{
    /** The partner columns that hold a portal domain. */
    public const COLUMNS = ['partner_domain', 'partner_subdomain', 'borrower_domain'];

    private const PARTNER_ID_KEY = 'domain_partner_id:';
    private const PORTAL_KEY = 'domain_portal:';

    /** A domain as it is looked up: lower case, trimmed, without "www.". */
    public static function normalise(?string $domain): string
    {
        return (string) preg_replace('/^www\./', '', strtolower(trim((string) $domain)));
    }

    public static function partnerIdKey(string $domain): string
    {
        return self::PARTNER_ID_KEY . self::normalise($domain);
    }

    public static function portalKey(string $domain): string
    {
        return self::PORTAL_KEY . self::normalise($domain);
    }

    /** The partner that owns $domain, else the host. Not cached. */
    public static function lookup(string $domain): ?int
    {
        $domain = self::normalise($domain);

        $partnerId = Partner::where(function ($query) use ($domain) {
            foreach (self::COLUMNS as $column) {
                $query->orWhere($column, $domain);
            }
        })->value('id');

        return $partnerId ?? Partner::where('is_host', true)->value('id');
    }

    /** Clears what is cached for each of $domains. */
    public static function forget(?string ...$domains): void
    {
        foreach (array_unique(array_filter(array_map([self::class, 'normalise'], $domains))) as $domain) {
            Cache::forget(self::partnerIdKey($domain));
            Cache::forget(self::portalKey($domain));
        }
    }

    /** Clears every domain $partner has, or had before this save. */
    public static function forgetPartner(Partner $partner): void
    {
        $domains = [];
        foreach (self::COLUMNS as $column) {
            $domains[] = $partner->getAttribute($column);
            $domains[] = $partner->getOriginal($column);
        }

        self::forget(...$domains);
    }
}
