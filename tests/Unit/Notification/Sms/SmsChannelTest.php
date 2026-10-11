<?php

namespace Tests\Unit\Notification\Sms;

use App\Notifications\DynamicNotification;
use App\Services\FCH\Common\Notification\RenderedNotification;
use App\Services\FCH\Common\Notification\Sms\SmsChannel;
use App\Services\FCH\Common\Notification\Sms\SmsGateway;
use App\Services\FCH\Common\Notification\Sms\SmsSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The notification channel hands the recipient's number, partner and the
 * rendered text to SmsSender; sending, normalising and failure handling live
 * there.
 */
#[CoversClass(SmsChannel::class)]
class SmsChannelTest extends TestCase
{
    private function notification(): DynamicNotification
    {
        return new DynamicNotification('test.sms', new RenderedNotification(
            title: 'Title',
            body: 'Body',
            url: null,
            icon: null,
            category: 'system',
            sms: 'Your request #7 was approved.',
        ), ['sms']);
    }

    private function notifiable(?string $phone, ?int $partnerId = 5): object
    {
        return new class($phone, $partnerId) {
            public function __construct(private ?string $phone, public ?int $partner_id)
            {
            }

            public function routeNotificationFor(string $driver, $notification = null): ?string
            {
                return $driver === 'sms' ? $this->phone : null;
            }
        };
    }

    private function recordingSender(): object
    {
        return new class extends SmsSender {
            public array $calls = [];

            public function __construct()
            {
            }

            public function send(?string $phone, ?int $partnerId, string $message, array $context = []): bool
            {
                $this->calls[] = [$phone, $partnerId, $message, $context];

                return true;
            }
        };
    }

    #[Test]
    public function it_hands_the_number_partner_and_text_to_the_sender(): void
    {
        $sender = $this->recordingSender();

        (new SmsChannel($sender))->send($this->notifiable('(555) 123-4567'), $this->notification());

        $this->assertSame([['(555) 123-4567', 5, 'Your request #7 was approved.', ['type' => 'test.sms']]], $sender->calls);
    }

    #[Test]
    public function a_recipient_without_a_number_is_not_handed_over(): void
    {
        $sender = $this->recordingSender();

        (new SmsChannel($sender))->send($this->notifiable(null), $this->notification());
        (new SmsChannel($sender))->send($this->notifiable(''), $this->notification());

        $this->assertSame([], $sender->calls);
    }
}

class RecordingSmsGateway implements SmsGateway
{
    /** @var array<int, array{0: string, 1: string}> */
    public array $sent = [];

    public function send(string $to, string $message): void
    {
        $this->sent[] = [$to, $message];
    }
}
