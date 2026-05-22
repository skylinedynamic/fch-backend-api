<?php

use App\Models\Partner\Partner;
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
        return Cache::remember("domain_partner_id:{$domain}", now()->addMinutes(120), function () use ($domain) {
            $partner = Partner::where('partner_domain', $domain)
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
            return $partner->partner_domain ?? config('app.frontend_partner_url');
        }
        $partner = auth()->user()->partner;
        $domain = $partner->partner_domain ?? config('app.frontend_partner_url');
        return $domain;
    }
}

if (!function_exists('getBorrowerDomain')) {
    function getBorrowerDomain(int $partnerId = null): ?string
    {
        if($partnerId){
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
