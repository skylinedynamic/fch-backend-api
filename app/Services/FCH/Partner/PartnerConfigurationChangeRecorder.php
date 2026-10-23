<?php

namespace App\Services\FCH\Partner;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use App\Services\FCH\Common\Notification\Notifier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records a change the host made to a partner's settings from Partner profile ›
 * Configuration: an entry on the partner's activity log, visible to everyone,
 * saying what was done and which fields changed (old → new), and a bell
 * notification (pushed live) to the partner's own logins.
 *
 * Credentials are never written: a password, secret, token, key or SID is
 * only reported as "changed".
 *
 * Failures are logged, never thrown: the change itself has already been saved.
 */
class PartnerConfigurationChangeRecorder
{
    public const AREA_EMAIL = 'email';
    public const AREA_SMS = 'sms';
    public const AREA_BRANDING = 'branding';
    public const AREA_PAYMENT = 'payment';

    /** Each area's wording and its page under the partner's own Settings. */
    private const AREAS = [
        self::AREA_EMAIL => ['label' => 'email configuration', 'section' => 'email-configuration'],
        self::AREA_SMS => ['label' => 'SMS configuration', 'section' => 'sms-configuration'],
        self::AREA_BRANDING => ['label' => 'branding', 'section' => 'branding'],
        self::AREA_PAYMENT => ['label' => 'payment settings', 'section' => 'payment-integration'],
    ];

    /** Field names that hold credentials - reported as "changed", never shown. */
    private const SECRET_PATTERN = '/(^|_)(password|secret|token|key|sid)(_|$)/i';

    /** Word forms kept upper case when a field name is turned into a label. */
    private const ACRONYMS = ['smtp' => 'SMTP', 'aws' => 'AWS', 'ses' => 'SES', 'sid' => 'SID', 'sms' => 'SMS', 'url' => 'URL', 'id' => 'ID'];

    public function __construct(
        private ActivityLogService $activityLog,
        private Notifier $notifier,
    ) {
    }

    /**
     * @param  string  $summary  What was done, after the user's name, e.g.
     *                           "updated the SMS configuration".
     * @param  array<string, array{label: string, from: ?string, to: ?string}>  $changes
     *         diff()'s field => change map - the activity sidebar's "Show changes" table.
     */
    public function record(int $partnerId, string $area, string $summary, array $changes = []): void
    {
        try {
            $partner = Partner::find($partnerId);
            if (!$partner) {
                return;
            }

            $user = Auth::user();
            $changedBy = $user?->name ?: 'The host';
            $host = Partner::whereKey($user?->partner_id)->value('company_name') ?: 'The host';
            // The changes themselves are shown as a table under the entry.
            $description = "{$changedBy} {$summary}.";

            $this->activityLog->logSystemEvent(
                Partner::class,
                $partner->id,
                $partner->id,
                null,
                'Configuration updated',
                $description,
                ActivityLog::LOG_TYPE_PARTNER_CONFIGURATION_UPDATE,
                ['area' => $area, 'summary' => $summary, 'changes' => $changes],
                ActivityLog::VISIBILITY_EVERYONE
            );

            $this->notifier->send('partner.configuration.updated', $partner, [
                'host' => $host,
                'area' => self::AREAS[$area]['label'],
                'section' => self::AREAS[$area]['section'],
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to record a partner configuration change', [
                'partner_id' => $partnerId,
                'area' => $area,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The fields that differ between two snapshots, keyed by field name, in the
     * shape the activity sidebar's "Show changes" table reads:
     * ['label' => …, 'from' => old, 'to' => new]. An empty value is null (shown
     * as "—"); a credential's values are never kept - it reads "Changed".
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, string|null>  $labels  Field name => label, where the default reads badly.
     * @param  string[]  $secrets  Extra field names to treat as credentials.
     * @return array<string, array{label: string, from: ?string, to: ?string}>
     */
    public static function diff(array $before, array $after, array $labels = [], array $secrets = []): array
    {
        $changes = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if (self::normalise($old) === self::normalise($new)) {
                continue;
            }

            $label = $labels[$key] ?? self::label((string) $key);
            $isSecret = in_array($key, $secrets, true) || preg_match(self::SECRET_PATTERN, (string) $key);

            $changes[(string) $key] = $isSecret
                ? ['label' => $label, 'from' => null, 'to' => 'Changed']
                : ['label' => $label, 'from' => self::show($old), 'to' => self::show($new)];
        }

        return $changes;
    }

    private static function normalise(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_array($value) ? json_encode($value) : (string) $value;
    }

    private static function show(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return Str::limit(is_array($value) ? json_encode($value) : (string) $value, 80);
    }

    /** "smtp_host" → "SMTP host", "primary_color" → "Primary color". */
    private static function label(string $key): string
    {
        $words = array_map(
            fn (string $word) => self::ACRONYMS[strtolower($word)] ?? strtolower($word),
            explode('_', $key)
        );

        return ucfirst(implode(' ', $words));
    }
}
