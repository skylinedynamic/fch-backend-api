<?php

namespace Tests\Integration\Mail;

use App\Mail\Borrower\BorrowerDocumentReviewedMail;
use App\Mail\Borrower\BorrowerVerificationStatusMail;
use App\Mail\Borrower\ChangeRequestStatusMail;
use App\Models\Partner\Partner;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * R3-BUG-013: a white-label client's "document rejected" mail came branded as
 * its partner but linked to the host's client portal, where the client cannot
 * sign in. The link read the signed-in user's partner - nobody, in the queue
 * worker that renders the mail, so it fell back to the host portal - instead of
 * the client's partner the mail is already branded with. The verification and
 * change-request mails built theirs the same way.
 */
class BorrowerMailPortalLinkTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_borrower_url' => 'app.example.com']);
        $this->host = $this->makeHostPartner();
    }

    /** @return array<string, array{Closure(int): Mailable, string, string}> */
    public static function mails(): array
    {
        return [
            'document rejected' => [
                fn (int $partnerId) => new BorrowerDocumentReviewedMail(['borrower_name' => 'Dana', 'document_name' => "Driver's License", 'status' => 'Rejected', 'reason' => 'Blurry'], $partnerId),
                'documentsUrl', 'documents',
            ],
            'verification decided' => [
                fn (int $partnerId) => new BorrowerVerificationStatusMail(['subject' => 'Verification update', 'borrower_name' => 'Dana', 'status' => 'approved', 'note' => null, 'task_results' => []], $partnerId),
                'loginUrl', 'login',
            ],
            'change request decided' => [
                fn (int $partnerId) => new ChangeRequestStatusMail(['subject' => 'Change request', 'borrower_name' => 'Dana', 'field_name' => 'Business phone', 'status' => 'Approved', 'new_value' => '+1 555 0199', 'note' => null], $partnerId),
                'loginUrl', 'login',
            ],
        ];
    }

    private function link(Mailable $mail, string $key): string
    {
        $mail->envelope();

        return (string) ($mail->content()->with[$key] ?? '');
    }

    #[Test]
    #[DataProvider('mails')]
    public function a_white_label_clients_mail_links_to_its_partners_portal(Closure $make, string $key, string $path): void
    {
        $wanda = $this->makeWhiteLabelPartner(['borrower_domain' => 'wanda-borrower.example.com']);

        // As the queue worker renders it: nobody is signed in.
        $this->assertSame("https://wanda-borrower.example.com/{$path}", $this->link($make($wanda->id), $key));
    }

    #[Test]
    #[DataProvider('mails')]
    public function the_reviewers_own_partner_never_decides_the_link(Closure $make, string $key, string $path): void
    {
        $wanda = $this->makeWhiteLabelPartner(['borrower_domain' => 'wanda-borrower.example.com']);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->assertSame("https://wanda-borrower.example.com/{$path}", $this->link($make($wanda->id), $key));
    }

    #[Test]
    #[DataProvider('mails')]
    public function a_host_clients_mail_links_to_the_host_portal(Closure $make, string $key, string $path): void
    {
        $this->assertSame("https://app.example.com/{$path}", $this->link($make($this->host->id), $key));
    }

    /** A white-label partner with no portal of its own: no link, never the host's (as the account-created mail). */
    #[Test]
    #[DataProvider('mails')]
    public function a_partner_without_a_client_portal_gets_no_link(Closure $make, string $key): void
    {
        $noPortal = $this->makeWhiteLabelPartner(['borrower_domain' => null]);

        $this->assertSame('', $this->link($make($noPortal->id), $key));
    }

    #[Test]
    public function the_document_rejected_mail_shows_the_partners_link_and_no_button_without_one(): void
    {
        $wanda = $this->makeWhiteLabelPartner(['borrower_domain' => 'wanda-borrower.example.com']);
        $data = ['borrower_name' => 'Dana', 'document_name' => "Driver's License", 'status' => 'Rejected', 'reason' => 'Blurry'];

        $html = (new BorrowerDocumentReviewedMail($data, $wanda->id))->render();
        $this->assertStringContainsString('https://wanda-borrower.example.com/documents', $html);
        $this->assertStringNotContainsString('app.example.com', $html);

        $html = (new BorrowerDocumentReviewedMail($data, $this->makeWhiteLabelPartner(['borrower_domain' => null])->id))->render();
        $this->assertStringNotContainsString('Go to My Documents', $html);
        $this->assertStringNotContainsString('app.example.com', $html);
    }
}
