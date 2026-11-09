<?php

namespace App\Support\EmbedForm;

/**
 * The address of the page an embedded form was framed on, as kept on a
 * submission (origin_url).
 *
 * It comes from the applicant's browser, so it can say anything: it is a label
 * for staff and nothing should trust it. Staff also see it as a link, so only a
 * plain web address is kept. The query and fragment are dropped because they can
 * carry personal data, and a bad value is dropped rather than refused, since a
 * label is no reason to lose an application.
 */
final class FramingPage
{
    /** The width of embedded_form_submissions.origin_url. */
    private const MAX_LENGTH = 500;

    private const WEB_SCHEMES = ['http', 'https'];

    public static function clean(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $parts = parse_url($value);
        $scheme = strtolower($parts['scheme'] ?? '');

        if (!in_array($scheme, self::WEB_SCHEMES, true) || empty($parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $clean = "{$scheme}://{$parts['host']}{$port}" . ($parts['path'] ?? '/');

        return strlen($clean) <= self::MAX_LENGTH ? $clean : null;
    }
}
