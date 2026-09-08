<?php

use App\Models\Partner\Partner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

if (!function_exists('encodeUrl')) {
    /**
     * Encode URL by replacing spaces with %20
     *
     * @param string $url
     * @return string
     */
    function encodeUrl(string $url): string
    {
        // Replace spaces with %20
        $url = str_replace(' ', '%20', $url);

        // Handle special characters like en-dashes (–) or em-dashes (—) that str_replace misses
        return preg_replace_callback('/[^\w\-\.\/:\?#\[\]@!$&\'\(\)\*\+,;=%]/u', function ($matches) {
            return rawurlencode($matches[0]);
        }, $url);
    }
}

if (!function_exists('formatComma')) {
    /**
     * Format number with commas.
     *
     * @param mixed $value
     * @param int $decimal
     * @return string|null
     */
    function formatComma($value, int $decimal = 2): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return number_format((float) $value, $decimal, '.', ',');
    }
}


if (!function_exists('currencyFormat')) {
    /**
     * Format number with commas.
     *
     * @param mixed $value
     * @param int $decimal
     * @return string|null
     */
    function currencyFormat($value, $decimal = 2, $symbol = '$', $conversionRate = 1, $thousandSeparator = ','): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $symbol . number_format((float) $value * $conversionRate, $decimal, '.', $thousandSeparator);
    }
}

if (!function_exists('formatAmountRange')) {
    /**
     * Format a numerical range string (e.g. "5000-250000") into currency format ("$5,000 - $250,000").
     *
     * @param string|null $range
     * @param int $decimal
     * @param string $symbol
     * @return string|null
     */
    function formatAmountRange(?string $range, int $decimal = 0, string $symbol = '$'): ?string
    {
        if (empty($range)) {
            return null;
        }

        $parts = explode('-', $range);
        if (count($parts) === 2) {
            $min = trim(str_replace(['$', ','], '', $parts[0]));
            $max = trim(str_replace(['$', ','], '', $parts[1]));

            if (is_numeric($min) && is_numeric($max)) {
                return currencyFormat((float) $min, $decimal, $symbol) . ' - ' . currencyFormat((float) $max, $decimal, $symbol);
            }
            return trim($parts[0]) . ' - ' . trim($parts[1]);
        }

        $clean = trim(str_replace(['$', ','], '', $range));
        if (is_numeric($clean)) {
            return currencyFormat((float) $clean, $decimal, $symbol);
        }

        return $range;
    }
}

if (!function_exists('normalizeDate')) {
    /**
     * Normalize date string to Y-m-d format.
     *
     * @param string|null $date
     * @return string|null
     */
    function normalizeDate($date)
    {
        if (empty($date)) {
            return null;
        }

        try {
            // Handle mm-dd-yyyy or mm/dd/yyyy
            if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $date, $matches)) {
                return sprintf('%04d-%02d-%02d', $matches[3], $matches[1], $matches[2]);
            }

            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception $e) {
            Log::error('Date parsing failed', ['date' => $date, 'error' => $e->getMessage()]);
            return null;
        }
    }
}

if (!function_exists('getPartnerIdFromOrigin')) {
    /**
     * Resolve Partner ID from the request Origin or Host.
     * Useful for unauthenticated requests to get tenant context based on domain.
     *
     * @return int|null
     */
    function getPartnerIdFromOrigin()
    {
        $request = request();
        $origin = $request->header('Origin');

        // Resolve domain from Origin or Host fallback
        $domain = ($origin && parse_url($origin, PHP_URL_HOST)) ? parse_url($origin, PHP_URL_HOST) : $request->getHost();

        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^www\./', '', $domain);

        // Cache the lookup for performance
        return Cache::remember("domain_partner_id:{$domain}", 0, function () use ($domain) {
            $partner = Partner::where('partner_domain', $domain)
                ->orWhere('partner_subdomain', $domain)
                ->orWhere('borrower_domain', $domain)
                ->first();

            if ($partner) {
                return $partner->id;
            }

            // Fallback to Host ID if no domain match (represents general/default context)
            $host = Partner::where('is_host', true)->first();
            return $host ? $host->id : null;
        });
    }
}

if (!function_exists('getConfigPartner')) {
    function getConfigPartner()
    {
        $partner = auth()->user()?->partner ?? Partner::find(getPartnerIdFromOrigin());

        if ($partner?->is_host || $partner?->partner_type == Partner::PARTNER_TYPE_WHITE_LABEL) {
            return $partner;
        }

        return Partner::host();
    }
}

if (!function_exists('getPartnerDomain')) {
    function getPartnerDomain(int $partnerId = null): ?string
    {
        if ($partnerId) {
            $partner = Partner::find($partnerId);
            return $partner->partner_domain ?? $partner->partner_subdomain ?? config('app.frontend_partner_url');
        }
        $partner = auth()->check() ? auth()->user()->partner : null;
        $domain = $partner->partner_domain ?? $partner->partner_subdomain ?? config('app.frontend_partner_url');
        return $domain;
    }
}

if (!function_exists('getBorrowerDomain')) {
    /**
     * Resolve the borrower-portal domain a borrower of this partner can actually
     * log in through.
     *
     * Falling back to the reserved default borrower portal
     * (config('app.frontend_borrower_url')) is only valid for the host and for a
     * Certified partner with no domain of its own — see
     * PortalIsolationService::isUserAllowedOnDomain(), which only lets those two
     * onto that domain. A White-Label partner (or any other non-host type) with
     * no borrower_domain has no domain a borrower can log in through at all;
     * falling back to the reserved default there would hand out a login link the
     * isolation check then rejects. Returns null in that case so callers (email
     * templates in particular) can omit the login link instead of sending a
     * broken one.
     */
    function getBorrowerDomain(int $partnerId = null): ?string
    {
        $partner = $partnerId
            ? Partner::find($partnerId)
            : (auth()->check() ? auth()->user()->partner : null);

        if (!$partner) {
            return null;
        }

        if (!empty($partner->borrower_domain)) {
            return $partner->borrower_domain;
        }

        if ($partner->is_host || $partner->partner_type === Partner::PARTNER_TYPE_CERTIFIED) {
            return config('app.frontend_borrower_url');
        }

        return null;
    }
}

if (!function_exists('getEmbedFormBaseUrl')) {
    /**
     * Resolve the base URL used for embedded loan application form links
     * (the direct link and the iframe embed snippet), based on the
     * authenticated user's partner:
     *
     * - Local environment: always the app base URL — custom/sub-domains
     *   don't resolve locally.
     * - Host partner: the app base URL.
     * - White-Label partner: partner_domain when active_custom_url is
     *   enabled (falling back to partner_subdomain if partner_domain is
     *   empty), otherwise partner_subdomain.
     * - Certified partner: partner_subdomain.
     * - No partner / no domain configured: the app base URL.
     */
    function getEmbedFormBaseUrl(): string
    {
        $baseUrl = rtrim(config('app.url'), '/');

        if (app()->environment('local')) {
            return $baseUrl;
        }

        $partner = auth()->user()?->partner;

        if (!$partner || $partner->is_host) {
            return $baseUrl;
        }

        $domain = null;

        if ($partner->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL) {
            $domain = $partner->active_custom_url
                ? ($partner->partner_domain ?: $partner->partner_subdomain)
                : $partner->partner_subdomain;
        } elseif ($partner->partner_type === Partner::PARTNER_TYPE_CERTIFIED) {
            $domain = $partner->partner_subdomain;
        }

        if (empty($domain)) {
            return $baseUrl;
        }

        $domain = preg_replace('/^https?:\/\//i', '', trim($domain));

        return 'https://' . rtrim($domain, '/');
    }
}

if (!function_exists('concatUrl')) {
    /**
     * Concatenate domain and path using appropriate protocol.
     *
     * @param string|null $domain
     * @param string $path
     * @param string|null $fallback
     * @return string
     */
    function concatUrl(?string $domain, string $path, ?string $fallback = null): string
    {
        $target = $domain ?: $fallback;

        if (empty($target)) {
            return '';
        }

        $target = trim($target);

        // Check if domain explicitly specifies http:// or https://
        $hasHttp = (bool) preg_match('/^http:\/\//i', $target);
        $hasHttps = (bool) preg_match('/^https:\/\//i', $target);

        // Detect local environment (localhost, 127.0.0.1, .test, .local)
        $isLocal = app()->isLocal()
            || str_contains($target, 'localhost')
            || str_contains($target, '127.0.0.1')
            || str_ends_with(parse_url($target, PHP_URL_HOST) ?? $target, '.test')
            || str_ends_with(parse_url($target, PHP_URL_HOST) ?? $target, '.local');

        // Clean domain
        $cleanDomain = preg_replace('/^https?:\/\//i', '', $target);
        $cleanDomain = rtrim($cleanDomain, '/');

        if ($hasHttps) {
            $protocol = 'https://';
        } elseif ($hasHttp) {
            $protocol = 'http://';
        } else {
            $protocol = $isLocal ? 'http://' : 'https://';
        }

        return $protocol . $cleanDomain . '/' . ltrim($path, '/');
    }
}

if (!function_exists('make_url')) {

    function make_url(string $domain, string $path = ''): string
    {
        // Remove extra spaces
        $domain = trim($domain);

        // Add https if missing
        if (!preg_match("~^(http|https)://~i", $domain)) {
            $domain = 'https://' . $domain;
        }

        // Remove trailing slash from domain
        $domain = rtrim($domain, '/');

        // Clean path
        $path = ltrim($path, '/');

        return $path ? "{$domain}/{$path}" : $domain;
    }
}

if (!function_exists('toDateOnly')) {
    /**
     * Render a date-only value as a plain Y-m-d string.
     *
     * Counterpart to toTimezone(). A DATE column holds a calendar date with no
     * time and no zone - "paid through 2026-08-31" is the same day everywhere -
     * so it must NOT be shifted into the caller's timezone on the way out.
     * Converting it would read the stored value as midnight UTC and hand any
     * viewer behind UTC the previous day.
     *
     * Use this for DATE columns; use toTimezone() only for DATETIME/TIMESTAMP
     * columns, which are real instants and do need converting.
     *
     * @param mixed $date
     * @param string $format
     * @return string|null
     */
    function toDateOnly($date, string $format = 'Y-m-d'): ?string
    {
        if (empty($date)) {
            return null;
        }

        try {
            return Carbon::parse($date)->format($format);
        } catch (\Exception $e) {
            Log::error('Date formatting failed', [
                'date' => $date,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}

if (!function_exists('toTimezone')) {
    /**
     * Convert a date/time value to the specified timezone and format.
     *
     * @param mixed $datetime
     * @param string|null $timezone
     * @param string $format
     * @return string|null
     */
    function toTimezone($datetime, ?string $timezone = null, string $format = 'Y-m-d'): ?string
    {
        if (empty($datetime)) {
            return null;
        }

        $timezone ??= request()->header('x-timezone', config('app.timezone', 'UTC'));

        try {
            return Carbon::parse($datetime)
                ->setTimezone($timezone)
                ->format($format);
        } catch (\Exception $e) {
            Log::error('Timezone conversion failed', [
                'datetime' => $datetime,
                'timezone' => $timezone,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}

if (!function_exists('calculateTimeInBusiness')) {
    function calculateTimeInBusiness($startDate)
    {
        if (empty($startDate)) {
            return 'N/A';
        }

        $start = Carbon::parse($startDate);
        $now = now();

        if ($start->isFuture()) {
            return '0 Days';
        }

        $diff = $start->diff($now);

        $parts = [];
        if ($diff->y > 0) {
            $parts[] = $diff->y . ' Year' . ($diff->y > 1 ? 's' : '');
        }
        if ($diff->m > 0) {
            $parts[] = $diff->m . ' Month' . ($diff->m > 1 ? 's' : '');
        }
        if ($diff->d > 0 || empty($parts)) {
            $parts[] = $diff->d . ' Day' . ($diff->d > 1 ? 's' : '');
        }

        return implode(' ', $parts);
    }
}
