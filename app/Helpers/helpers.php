<?php

use App\Models\Partner\Partner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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

            return \Illuminate\Support\Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Date parsing failed', ['date' => $date, 'error' => $e->getMessage()]);
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
        $partner = auth()->user()->partner;
        $domain = $partner->partner_domain ?? $partner->partner_subdomain ?? config('app.frontend_partner_url');
        return $domain;
    }
}

if (!function_exists('getBorrowerDomain')) {
    function getBorrowerDomain(int $partnerId = null): ?string
    {
        if ($partnerId) {
            $partner = Partner::find($partnerId);
            return $partner->borrower_domain ?? config('app.frontend_borrower_url');
        }
        $partner = auth()->user()->partner;
        $domain = $partner->borrower_domain ?? config('app.frontend_borrower_url');
        return $domain;
    }
}

if (!function_exists('concatUrl')) {
    /**
     * Concatenate domain and path using HTTPS protocol.
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

        // Remove any existing protocol
        $target = preg_replace('/^https?:\/\//i', '', trim($target));

        // Enforce HTTPS
        $target = 'https://' . rtrim($target, '/');

        return $target . '/' . ltrim($path, '/');
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
            \Illuminate\Support\Facades\Log::error('Timezone conversion failed', [
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
