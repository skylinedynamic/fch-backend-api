<?php

namespace App\Services\FCH\Common\Application;

use App\Models\Borrower\FundingApplication;
use Carbon\Carbon;
use Throwable;

/**
 * What the electronic signature certificate shows, taken from the signature
 * record (see SignatureRecorder) - never filled in with a plausible default.
 * Anything a signature from before a field was recorded does not carry shows
 * as "Not recorded" rather than as an invented value.
 */
class SignatureCertificateService
{
    public const NOT_RECORDED = 'Not recorded';

    private const METHODS = [
        SignatureRecorder::METHOD_BORROWER_PORTAL => [
            'label' => 'Borrower portal',
            'authentication' => "Signed in to the borrower's own account",
        ],
        SignatureRecorder::METHOD_PARTNER_PORTAL => [
            'label' => 'Partner portal',
            'authentication' => "Signed in to a partner user's account and submitted on the borrower's behalf",
        ],
        SignatureRecorder::METHOD_EMBEDDED_FORM => [
            'label' => 'Public embedded form',
            'authentication' => 'No account sign-in - the signer is identified by the email address given',
        ],
    ];

    /**
     * @param FundingApplication $application
     * @param array $brandingData
     * @param array|null $signatureData
     * @return array
     */
    public static function getTemplateData($application, $brandingData, $signatureData = null)
    {
        $rawSigData = $signatureData ?? $application->signature_data ?? [];
        $sigData = is_string($rawSigData) ? json_decode($rawSigData, true) : $rawSigData;
        if (!is_array($sigData)) {
            $sigData = [];
        }

        $appId = $application->application_number ?? ('FUND-' . str_pad($application->id, 6, '0', STR_PAD_LEFT));

        // Who the application was for, as it stood when signed.
        $borrowerName = ($sigData['borrower_name'] ?? null)
            ?: personName($application->first_name ?? null, $application->middle_name ?? null, $application->last_name ?? null)
            ?: self::NOT_RECORDED;
        $businessName = ($sigData['business_name'] ?? null) ?: ($application->business_name ?: self::NOT_RECORDED);
        // Only what was recorded at signing: today's status is not the status then.
        $rawStatus = $sigData['status_at_signing'] ?? null;
        $status = $rawStatus
            ? (FundingApplication::STATUS[$rawStatus] ?? ucfirst(str_replace('_', ' ', (string) $rawStatus)))
            : self::NOT_RECORDED;

        // Who signed, and how.
        $signedName = trim((string) ($sigData['signature_name'] ?? '')) ?: self::NOT_RECORDED;

        $method = self::METHODS[$sigData['signing_method'] ?? ''] ?? null;
        $signingMethod = $method['label'] ?? self::NOT_RECORDED;
        $authentication = $method['authentication'] ?? self::NOT_RECORDED;

        $account = $sigData['signer_account'] ?? null;
        if (is_array($account) && !empty($account['name'])) {
            $signerAccount = $account['name'] . (!empty($account['email']) ? " ({$account['email']})" : '');
        } elseif (($sigData['signing_method'] ?? null) === SignatureRecorder::METHOD_EMBEDDED_FORM) {
            $signerAccount = 'None' . (!empty($sigData['signer_email']) ? " - email given: {$sigData['signer_email']}" : '');
        } else {
            $signerAccount = self::NOT_RECORDED;
        }

        // From where.
        $ip = ($sigData['ip_address'] ?? null) ?: self::NOT_RECORDED;
        $browser = self::recorded($sigData['browser_name'] ?? null);
        if ($browser !== self::NOT_RECORDED && !empty($sigData['browser_version']) && $sigData['browser_version'] !== 'Unknown') {
            $browser .= ' ' . $sigData['browser_version'];
        }
        $os = self::recorded($sigData['operating_system'] ?? null);
        $device = self::recorded($sigData['device_type'] ?? null);

        // When: the exact moment, in the signer's own timezone and in UTC.
        [$signedAtLocal, $signedAtUtc, $signedOn, $signerTimezone] = self::signingMoment($sigData);

        // What was accepted.
        $policies = null;
        if (!empty($sigData['policy_versions']) && is_array($sigData['policy_versions'])) {
            $policies = array_map(fn ($policy) => [
                'label' => $policy['label'] ?? '',
                'version' => 'v' . ($policy['version'] ?? '?'),
                'url' => $policy['url'] ?? null,
                'status' => self::consent($policy['accepted'] ?? null),
            ], array_values($sigData['policy_versions']));
        }
        $consents = [
            'esign' => self::consent($sigData['esign_consent'] ?? null),
            'terms' => self::consent($sigData['terms_consent'] ?? null),
            'credit' => self::consent($sigData['credit_consent'] ?? null),
            'legal' => self::consent($sigData['legal_binding_consent'] ?? null),
        ];

        // Which signed version of the application this certifies.
        $appVersion = $sigData['application_version'] ?? null;
        $appVersion = is_int($appVersion) || ctype_digit((string) $appVersion) ? 'Version ' . $appVersion : self::NOT_RECORDED;
        $sha = ($sigData['sha256'] ?? null) ?: self::NOT_RECORDED;
        $certId = ($sigData['certificate_id'] ?? null) ?: self::NOT_RECORDED;

        return compact(
            'application', 'sigData', 'appId', 'certId', 'appVersion', 'sha',
            'borrowerName', 'businessName', 'rawStatus', 'status',
            'signedName', 'signingMethod', 'authentication', 'signerAccount',
            'ip', 'browser', 'os', 'device',
            'signedAtLocal', 'signedAtUtc', 'signedOn', 'signerTimezone',
            'policies', 'consents'
        );
    }

    /**
     * The moment of signing as [in the signer's timezone, in UTC, the date, the
     * timezone]. signed_at is stored in UTC; older records hold it without an
     * offset, in the app's timezone (UTC).
     *
     * @return array{0: string|null, 1: string, 2: string, 3: string|null}
     */
    private static function signingMoment(array $sigData): array
    {
        if (empty($sigData['signed_at'])) {
            return [null, self::NOT_RECORDED, self::NOT_RECORDED, null];
        }

        try {
            $utc = Carbon::parse($sigData['signed_at'], config('app.timezone', 'UTC'))->utc();
        } catch (Throwable $e) {
            return [null, self::NOT_RECORDED, self::NOT_RECORDED, null];
        }

        $timezone = $sigData['timezone'] ?? null;
        $local = null;
        if (is_string($timezone) && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $inZone = $utc->copy()->setTimezone($timezone);
            $local = $inZone->format('M j, Y g:i:s A T') . ' (UTC' . $inZone->format('P') . ')';
        } else {
            $timezone = null;
        }

        $signedOn = $timezone ? $utc->copy()->setTimezone($timezone)->format('M j, Y') : $utc->format('M j, Y');

        return [$local, $utc->format('M j, Y g:i:s A') . ' UTC', $signedOn, $timezone];
    }

    private static function consent(mixed $value): string
    {
        if ($value === null) {
            return self::NOT_RECORDED;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Accepted' : 'Not accepted';
    }

    private static function recorded(mixed $value): string
    {
        return is_string($value) && $value !== '' && $value !== 'Unknown' ? $value : self::NOT_RECORDED;
    }
}
