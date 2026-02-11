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
