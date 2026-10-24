<?php

namespace Tests\Feature\MailConfiguration;

use App\Models\Partner\Partner;
use App\Services\Global\Notification\Mail\GenericNotificationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whose mail a notification (a password reset, a sign-in code) is: the
 * recipient's partner's - not whichever domain the request happened to come
 * from, which on a password reset is all there is, and on localhost is none.
 */
class GenericMailPartnerTest extends TestCase
{
    use RefreshDatabase;
    use MailConfigurationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPartners();
    }

    private function mailFor(array $mailData): ?int
    {
        $mail = new GenericNotificationMail(array_merge([
            'subject' => 'Reset Password Notification',
            'content' => ['lines' => []],
            'notification_type' => 'password_reset',
            'data' => [],
        ], $mailData));

        $property = new \ReflectionProperty($mail, 'mailPartnerId');
        $property->setAccessible(true);

        return $property->getValue($mail);
    }

    #[Test]
    public function a_signed_out_reset_is_the_recipients_partners_mail(): void
    {
        // Nobody is signed in and the request names no partner's domain.
        $this->assertFalse(auth()->check());

        $this->assertSame($this->whiteLabel->id, $this->mailFor(['user' => $this->owner($this->whiteLabel)]));
    }

    #[Test]
    public function a_certified_partners_recipient_gets_the_hosts_mail(): void
    {
        $certified = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Certified Co',
            'full_name' => 'Certified Owner',
            'email' => 'certified-' . uniqid() . '@example.test',
        ]);

        // It trades under the host's brand.
        $this->assertSame($this->host->id, $this->mailFor(['user' => $this->owner($certified)]));
    }

    #[Test]
    public function a_partner_named_on_the_mail_wins(): void
    {
        $this->assertSame($this->host->id, $this->mailFor([
            'user' => $this->owner($this->whiteLabel),
            'partner_id' => $this->host->id,
        ]));
    }

    #[Test]
    public function a_recipient_with_no_partner_falls_back_to_the_request(): void
    {
        $this->assertSame($this->host->id, $this->mailFor(['user' => null]));
    }
}
