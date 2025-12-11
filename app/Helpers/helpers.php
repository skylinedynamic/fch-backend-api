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
