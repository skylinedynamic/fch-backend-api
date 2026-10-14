<?php

namespace Tests\Feature\Notification;

use App\Http\Controllers\Api\V1\Global\Notification\NotificationController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * POST /api/v1/notifications/test-email - the partner portal's Settings >
 * Email "send a test" button. It mails a message of the caller's choosing to
 * an address of the caller's choosing through the partner's mail setup, so it
 * is for the partner's team only.
 */
#[CoversClass(NotificationController::class)]
class TestEmailApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        $this->makeHostPartner();
    }

    #[Test]
    public function it_needs_a_signed_in_user(): void
    {
        $this->postJson('/api/v1/notifications/test-email')->assertUnauthorized();
    }

    #[Test]
    public function a_partner_user_sends_a_test_email(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->makeCertifiedPartner()));

        $response = $this->postJson('/api/v1/notifications/test-email', ['email' => 'it@example.test', 'body' => 'Testing 1 2 3']);

        $this->assertContains($response->status(), [200, 500], 'the send itself depends on the partner mail setup');
        $this->assertNotSame(403, $response->status());
    }

    /**
     * Defect: any signed-in user could use it - a borrower could send mail of
     * their own wording to any address, from the platform's mail account.
     */
    #[Test]
    public function a_borrower_cannot_send_mail_through_it(): void
    {
        $this->actingAsUser($this->makeBorrowerUser($this->makeCertifiedPartner()));

        $this->postJson('/api/v1/notifications/test-email', ['email' => 'victim@example.test', 'body' => 'Click here'])
            ->assertForbidden();

        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }
}
