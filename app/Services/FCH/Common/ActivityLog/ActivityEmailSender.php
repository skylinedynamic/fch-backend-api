<?php

namespace App\Services\FCH\Common\ActivityLog;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Setting\EmailConfigurationService;
use App\Support\Mail\MailSenderName;

/**
 * Who a host's New Email from an activity goes out from.
 *
 * By default the mail settings of the partner the record belongs to (the
 * client's partner); the host can switch to its own. Either one without mail
 * settings of its own that can send goes out from the fallback, under its own
 * name (MailConfig, MailSenderName). A partner's own mail is not affected: it
 * goes out as before (getConfigPartner()).
 */
class ActivityEmailSender
{
    public function __construct(private readonly EmailConfigurationService $configurations)
    {
    }

    /**
     * The two choices the host is shown, and which one is picked first.
     *
     * @return array{default: string, options: array{partner: ?array, host: array}}
     */
    public function optionsFor(?int $recordPartnerId): array
    {
        $hostId = Partner::host()?->id;
        $partnerId = $this->ownerPartner($recordPartnerId, $hostId);

        return [
            'default' => $partnerId ? ActivityLog::EMAIL_SENDER_PARTNER : ActivityLog::EMAIL_SENDER_HOST,
            'options' => [
                ActivityLog::EMAIL_SENDER_PARTNER => $partnerId ? $this->option($partnerId) : null,
                ActivityLog::EMAIL_SENDER_HOST => $this->option($hostId),
            ],
        ];
    }

    /**
     * Whose mail settings an email goes out with. Null for anyone but the
     * host: the mail keeps choosing as it always has.
     */
    public function partnerIdFor(?User $user, ?string $sendFrom, ?int $recordPartnerId): ?int
    {
        if (!self::isHost($user)) {
            return null;
        }

        $hostId = Partner::host()?->id;
        $partnerId = $this->ownerPartner($recordPartnerId, $hostId);

        return $sendFrom === ActivityLog::EMAIL_SENDER_HOST || !$partnerId ? $hostId : $partnerId;
    }

    public static function isHost(?User $user): bool
    {
        return (bool) $user?->partner?->is_host;
    }

    /** The record's partner, unless it is the host's own record. */
    private function ownerPartner(?int $recordPartnerId, ?int $hostId): ?int
    {
        return $recordPartnerId && $recordPartnerId !== $hostId ? $recordPartnerId : null;
    }

    /** @return array{partner_id: ?int, name: string, address: ?string, uses_fallback: bool} */
    private function option(?int $partnerId): array
    {
        return [
            'partner_id' => $partnerId,
            'name' => MailSenderName::for($partnerId),
            'address' => $this->configurations->resolveFor($partnerId)?->from_address,
            'uses_fallback' => !$this->configurations->usesOwnConfiguration($partnerId),
        ];
    }
}
