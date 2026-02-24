<?php

if (!function_exists('encodeUrl')) {
    /**
     * Encode URL by replacing spaces with %20
     *
     * @param string $url
     * @return string
     */
    function encodeUrl(string $url): string
    {
        return str_replace(' ', '%20', $url);
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
        return number_format((float)$value, $decimal, '.', ',');
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
        return $symbol . number_format((float)$value * $conversionRate, $decimal, '.', $thousandSeparator);
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
