<?php

namespace App\Services\FCH\Common\Notification\Sms;

use App\Models\Setting\SmsConfiguration;
use Illuminate\Support\Facades\Http;

/**
 * Sends through Twilio's Messages API with one partner's own account.
 *
 * A messaging service, when set, is used instead of the from number: that is
 * how US A2P 10DLC traffic is normally registered with Twilio.
 */
class TwilioSmsGateway implements SmsGateway
{
    private const MESSAGES_URL = 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json';
    private const TIMEOUT_SECONDS = 15;

    public function __construct(private readonly SmsConfiguration $configuration)
    {
    }

    public function send(string $to, string $message): void
    {
        $accountSid = (string) $this->configuration->credential('account_sid');
        $payload = ['To' => $to, 'Body' => $message];

        $messagingServiceSid = $this->configuration->credential('messaging_service_sid');

        if ($messagingServiceSid) {
            $payload['MessagingServiceSid'] = $messagingServiceSid;
        } else {
            $payload['From'] = $this->configuration->from_number;
        }

        $response = Http::asForm()
            ->withBasicAuth($accountSid, (string) $this->configuration->credential('auth_token'))
            ->timeout(self::TIMEOUT_SECONDS)
            ->post(sprintf(self::MESSAGES_URL, rawurlencode($accountSid)), $payload);

        if ($response->failed()) {
            throw new SmsDeliveryException((string) ($response->json('message') ?? $response->status()));
        }
    }
}
