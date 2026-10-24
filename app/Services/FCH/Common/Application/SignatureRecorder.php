<?php

namespace App\Services\FCH\Common\Application;

use App\Models\User\User;
use DateTimeZone;
use Illuminate\Http\Request;

/**
 * The record kept when a funding application is signed - the same record
 * whether it was signed in the borrower portal, in the partner portal or on the
 * public embedded form - and so everything the electronic signature certificate
 * can prove later:
 *
 *  - who signed: the name signed, the account signed in (none on the public
 *    form, where the applicant is known only by the email they gave), and how;
 *  - where from: the signer's own IP address (the proxy in front of the API is
 *    trusted, see bootstrap/app.php) and the device;
 *  - when: the moment in UTC, plus the signer's own timezone to show it in;
 *  - what was accepted: each consent, and the version of each policy document
 *    in force - from config, never from what the browser posts.
 *
 * The signed version number and the signed copy are added when the copy is
 * kept (FundingApplicationDocumentService::generateAndLockFinalDocuments).
 */
class SignatureRecorder
{
    public const METHOD_BORROWER_PORTAL = 'borrower_portal';
    public const METHOD_PARTNER_PORTAL = 'partner_portal';
    public const METHOD_EMBEDDED_FORM = 'embedded_form';

    /** Each policy and the consent that accepts it. */
    private const POLICY_CONSENTS = [
        'esign' => 'esign_consent',
        'terms' => 'terms_consent',
        'privacy' => 'terms_consent',
        'credit_authorization' => 'credit_consent',
    ];

    /**
     * @param  array  $signature  signature_name, signature_type, signature_image,
     *                            signature_date, timezone, the four consents,
     *                            consents_data (the browser's own JSON) and
     *                            application_version (the form's build number)
     * @param  User|null  $account  the account signed in; null on the public form
     * @param  string|null  $signerEmail  the email the signer gave, when there is no account
     */
    public static function record(
        Request $request,
        string $method,
        array $signature,
        ?User $account,
        ?string $signerEmail = null
    ): array {
        $consents = [];
        foreach (['esign_consent', 'terms_consent', 'credit_consent', 'legal_binding_consent'] as $consent) {
            $consents[$consent] = filter_var($signature[$consent] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        $isEsign = ($signature['signature_type'] ?? 'typed') === 'esign';

        return array_merge(
            [
                'signature_name' => $signature['signature_name'] ?? null,
                'signature_type' => $isEsign ? 'esign' : 'typed',
                'signature_image' => $isEsign ? ($signature['signature_image'] ?? null) : null,
                // The date the signer wrote in the e-signature block; signed_at
                // below is the server's own record of the moment.
                'signature_date' => $isEsign ? ($signature['signature_date'] ?? null) : null,

                'signing_method' => $method,
                'signer_account' => $account ? [
                    'user_id' => $account->id,
                    'name' => $account->name,
                    'email' => $account->email,
                    'user_type' => $account->user_type,
                ] : null,
                'signer_email' => $signerEmail ?? $account?->email,

                'ip_address' => $request->ip(),
            ],
            self::device((string) $request->userAgent()),
            [
                'timezone' => self::timezone($signature['timezone'] ?? null),
                'signed_at' => now()->utc()->toIso8601String(),
            ],
            $consents,
            [
                'policy_versions' => self::policyVersions($consents),
                // The form's own build, as the browser reports it. Not the signed
                // version number, which the server sets when it keeps the copy.
                'form_version' => $signature['application_version'] ?? null,
                'consents_data' => self::clientConsents($signature['consents_data'] ?? null),
            ]
        );
    }

    /**
     * The policies in force, each with its version and whether it was accepted.
     * Without consents (the certificate's sample of what is in force) each is
     * left unanswered.
     *
     * @param  array<string, bool>|null  $consents
     * @return array<string, array{label: string, version: string, url: string|null, accepted: bool|null}>
     */
    public static function policyVersions(?array $consents = null): array
    {
        $versions = [];
        foreach (config('funding_application.signing.policies', []) as $key => $policy) {
            $consent = self::POLICY_CONSENTS[$key] ?? null;
            $versions[$key] = [
                'label' => $policy['label'],
                'version' => (string) $policy['version'],
                'url' => $policy['url'] ?? null,
                'accepted' => $consents !== null && $consent ? (bool) ($consents[$consent] ?? false) : null,
            ];
        }

        return $versions;
    }

    /** An IANA timezone the signer's browser reported, or null if it is not one. */
    private static function timezone(mixed $timezone): ?string
    {
        $timezone = is_string($timezone) ? trim($timezone) : '';

        return $timezone !== '' && in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : null;
    }

    /** The browser's per-checkbox timestamps, decoded defensively: it is applicant-supplied. */
    private static function clientConsents(mixed $json): ?array
    {
        if (is_array($json)) {
            return $json;
        }
        if (!is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
    }

    /** Browser, version, operating system and device type, read from the user agent. */
    private static function device(string $agent): array
    {
        $os = preg_match('/windows|win32|macintosh|mac os x|linux|android|iphone|ipad/i', $agent, $m) ? $m[0] : 'Unknown';
        $browser = preg_match('/chrome|firefox|safari|edge|opera|msie|trident/i', $agent, $m) ? $m[0] : 'Unknown';

        $browserVersion = 'Unknown';
        if ($browser !== 'Unknown') {
            if (preg_match('/version[\/ ]([\d.]+)/i', $agent, $m)) {
                $browserVersion = $m[1];
            } elseif (preg_match('/' . preg_quote($browser, '/') . '[\/ ]([\d.]+)/i', $agent, $m)) {
                $browserVersion = $m[1];
            }
        }

        $deviceType = 'Desktop';
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $agent)) {
            $deviceType = 'Tablet';
        } elseif (preg_match('/Mobile|iP(hone|od)|Android|BlackBerry|IEMobile|Kindle|NetFront|Silk-Accelerated|(hpw|web)OS|Fennec|Minimo|Opera M(obi|ini)|Blazer|Dolfin|Dolphin|Skyfire|Zune/i', $agent)) {
            $deviceType = 'Mobile';
        }

        return [
            'user_agent' => $agent,
            'browser_name' => ucfirst(strtolower($browser)),
            'browser_version' => $browserVersion,
            'operating_system' => self::osName($os),
            'device_type' => $deviceType,
        ];
    }

    private static function osName(string $os): string
    {
        return match (strtolower($os)) {
            'windows', 'win32' => 'Windows',
            'macintosh', 'mac os x' => 'macOS',
            'linux' => 'Linux',
            'android' => 'Android',
            'iphone', 'ipad' => 'iOS',
            default => 'Unknown',
        };
    }
}
