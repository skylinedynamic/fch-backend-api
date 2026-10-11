<?php

namespace App\Services\FCH\Common\Notification\Sms;

use App\Services\FCH\Common\Setting\SmsConfigurationService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place a text is sent from - notifications and 2FA codes alike.
 *
 * Picks the account (SmsConfigurationService::resolveFor), normalises the
 * stored number to E.164 for that account's country, and sends. It never
 * throws: a text that cannot go out - no enabled account, no usable number,
 * the provider refusing or unreachable - is logged and reported as not sent,
 * so whatever triggered it carries on. Numbers are logged masked.
 */
class SmsSender
{
    public function __construct(
        private readonly SmsConfigurationService $configurations,
        private readonly SmsGatewayFactory $gateways,
    ) {
    }

    /**
     * @param array<string, mixed> $context Extra log context (type, user_id...)
     * @return bool Whether the provider accepted the message
     */
    public function send(?string $phone, ?int $partnerId, string $message, array $context = []): bool
    {
        $context += ['partner_id' => $partnerId, 'to' => PhoneNumber::mask($phone)];

        try {
            $configuration = $this->configurations->resolveFor($partnerId);

            if (!$configuration) {
                Log::info(__('notification.sms.logs.no_enabled_configuration'), $context);

                return false;
            }

            if (!$phone) {
                Log::warning(__('notification.sms.errors.no_phone'), $context);

                return false;
            }

            $to = PhoneNumber::toE164($phone, (string) $configuration->country);

            if ($to === null) {
                Log::warning(__('notification.sms.errors.invalid_number'), $context);

                return false;
            }

            $this->gateways->make($configuration)->send($to, $message);

            return true;
        } catch (Throwable $e) {
            Log::error(__('notification.sms.errors.send_failed'), $context + ['reason' => $e->getMessage()]);

            return false;
        }
    }
}
