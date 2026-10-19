<?php

namespace Tests\Unit\Profile;

use App\Mail\Borrower\ChangeRequestStatusMail;
use App\Mail\Partner\NewChangeRequestSubmittedMail;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerChangeRequest;
use App\Models\Partner\Partner;
use App\Services\Profile\ChangeRequestNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\Support\FakesBell;
use Tests\TestCase;

/**
 * Who is told about a profile change request, and from whose mailbox.
 *
 * The same two rules as the credit builder verification flow, because it is the
 * same shape of workflow - a borrower asks, the host decides:
 *
 *   borrower submits -> the HOST is told,     from the HOST's mailbox
 *   decision reached -> the BORROWER is told, from THEIR PARTNER's mailbox
 *
 * Which mailbox is used is not visible in the message - it is the
 * `mailPartnerId` the MailConfig trait resolves credentials from - so these
 * assert that property directly. ChangeRequestStatusMail accepted a partner id
 * and then ignored it, which sent every borrower's mail out through the
 * reviewing admin's mail server, under the wrong brand.
 *
 * No database: models are built in memory and the one lookup the notifier needs
 * is overridden below.
 */
#[CoversClass(ChangeRequestNotifier::class)]
class ChangeRequestNotifierTest extends TestCase
{
    use FakesBell;

    private const HOST_ID = 1;
    private const PARTNER_ID = 42;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakeBell();
    }

    // ── Fixtures, all in memory ───────────────────────────────────────

    private function partner(int $id, array $attributes = []): Partner
    {
        $partner = new Partner(array_merge([
            'is_host' => false,
            'company_name' => 'Northwind Capital',
            'email' => 'accounts@northwind.test',
            'contact_person_email' => null,
        ], $attributes));

        $partner->id = $id;

        return $partner;
    }

    private function host(array $attributes = []): Partner
    {
        return $this->partner(self::HOST_ID, array_merge([
            'is_host' => true,
            'company_name' => 'Four Corner Holdings',
            'email' => 'ops@fourcorner.test',
        ], $attributes));
    }

    private function borrower(array $attributes = []): Borrower
    {
        $borrower = new Borrower(array_merge([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.test',
            'business_email' => null,
        ], $attributes));

        $borrower->id = 7;
        $borrower->partner_id = self::PARTNER_ID;

        return $borrower;
    }

    private function changeRequest(?Borrower $borrower = null, array $attributes = []): BorrowerChangeRequest
    {
        $request = new BorrowerChangeRequest(array_merge([
            'field_name' => 'date_of_birth',
            'visible_field_name' => 'Date of Birth',
            'new_value' => '1981-06-06',
            'status' => BorrowerChangeRequest::STATUS_APPROVED,
            'note' => 'Verified against the submitted document.',
        ], $attributes));

        $request->id = 99;
        $request->setRelation('borrower', $borrower ?? $this->borrower());

        return $request;
    }

    /**
     * The notifier with its single database lookup stubbed out.
     */
    private function notifier(?Partner $host): ChangeRequestNotifier
    {
        return new class($host) extends ChangeRequestNotifier {
            public function __construct(private ?Partner $host)
            {
            }

            protected function hostPartner(): ?Partner
            {
                return $this->host;
            }
        };
    }

    /** The mailbox a queued message will be sent from. */
    private function sendingPartnerId(object $mailable): ?int
    {
        $property = new ReflectionProperty($mailable, 'mailPartnerId');
        $property->setAccessible(true);

        return $property->getValue($mailable);
    }

    private function recipientsOf(object $mailable): array
    {
        return collect($mailable->to)->pluck('address')->all();
    }

    // ── A borrower submits ────────────────────────────────────────────

    #[Test]
    public function a_submission_is_announced_to_the_host_from_the_hosts_own_mailbox(): void
    {
        $this->notifier($this->host())->requestSubmitted($this->changeRequest());

        $queued = Mail::queued(NewChangeRequestSubmittedMail::class);

        $this->assertCount(1, $queued);
        $this->assertSame(['ops@fourcorner.test'], $this->recipientsOf($queued->first()));
        $this->assertSame(
            self::HOST_ID,
            $this->sendingPartnerId($queued->first()),
            "the submission notice must be sent from the host's mailbox"
        );
    }

    #[Test]
    public function only_the_hosts_account_email_is_used(): void
    {
        // contact_person_email is a different person, not a fallback inbox.
        // This flow did fall back to it before the send moved into the notifier.
        $host = $this->host([
            'email' => 'ops@fourcorner.test',
            'contact_person_email' => 'contact@fourcorner.test',
        ]);

        $this->notifier($host)->requestSubmitted($this->changeRequest());

        $this->assertSame(
            ['ops@fourcorner.test'],
            $this->recipientsOf(Mail::queued(NewChangeRequestSubmittedMail::class)->first())
        );
    }

    #[Test]
    public function a_contact_address_alone_is_not_written_to(): void
    {
        // No account email: reported, not redirected to the named contact.
        $host = $this->host(['email' => null, 'contact_person_email' => 'contact@fourcorner.test']);

        $this->notifier($host)->requestSubmitted($this->changeRequest());

        Mail::assertNothingQueued();
    }

    #[Test]
    public function nothing_is_sent_when_the_host_has_no_address(): void
    {
        // Logged and dropped: the change request row is already written.
        $host = $this->host(['email' => null, 'contact_person_email' => null]);

        $this->notifier($host)->requestSubmitted($this->changeRequest());

        Mail::assertNothingQueued();
    }

    // ── A decision is reached ─────────────────────────────────────────

    #[Test]
    public function a_decision_emails_the_borrower_from_their_own_partners_mailbox(): void
    {
        $this->notifier($this->host())->decisionMade($this->changeRequest());

        $queued = Mail::queued(ChangeRequestStatusMail::class);

        $this->assertCount(1, $queued);
        $this->assertSame(['jane@example.test'], $this->recipientsOf($queued->first()));

        // The whole point. A decision is made while an admin is authenticated,
        // so anything relying on the ambient partner sends this from the
        // reviewer's mailbox rather than the borrower's own partner's.
        $this->assertSame(
            self::PARTNER_ID,
            $this->sendingPartnerId($queued->first()),
            "the borrower's mail must be sent from their own partner's mailbox"
        );
    }

    #[Test]
    public function a_decision_emails_nobody_but_the_borrower(): void
    {
        $this->notifier($this->host())->decisionMade($this->changeRequest());

        Mail::assertQueuedCount(1);
        Mail::assertNotQueued(NewChangeRequestSubmittedMail::class);
    }

    #[Test]
    public function the_email_describes_the_field_in_words_the_borrower_recognises(): void
    {
        // visible_field_name is the label shown in the portal ("Date of Birth"),
        // not the column name. The borrower never saw `date_of_birth`.
        $this->notifier($this->host())->decisionMade($this->changeRequest());

        $data = Mail::queued(ChangeRequestStatusMail::class)->first()->mailData;

        $this->assertSame('Date of Birth', $data['field_name']);
        $this->assertSame('Jane Smith', $data['borrower_name']);
        $this->assertSame('1981-06-06', $data['new_value']);
        $this->assertSame(BorrowerChangeRequest::STATUS_APPROVED, $data['status']);
    }

    /**
     * Defect (D12): the decision email carried the requested new value as is,
     * so an SSN or EIN went out in plain email. They are masked to their last
     * four; a date of birth is still shown in full.
     */
    #[Test]
    public function an_ssn_or_ein_is_masked_in_the_decision_email(): void
    {
        $cases = [
            ['business_ein', 'Business EIN No', '12-3456789', '**-***6789'],
            ['last_4_ssn', 'Last 4 SSN Number', '6789', '****'],
            ['ssn', 'Social Security Number', '123-45-6789', '***-**-6789'],
        ];

        foreach ($cases as [$field, $label, $value, $masked]) {
            Mail::fake();
            $this->notifier($this->host())->decisionMade($this->changeRequest(attributes: [
                'field_name' => $field, 'visible_field_name' => $label, 'new_value' => $value,
            ]));

            $this->assertSame($masked, Mail::queued(ChangeRequestStatusMail::class)->first()->mailData['new_value'], $field);
        }
    }

    #[Test]
    public function the_column_name_stands_in_when_there_is_no_label(): void
    {
        $request = $this->changeRequest(null, ['visible_field_name' => null]);

        $this->notifier($this->host())->decisionMade($request);

        $this->assertSame(
            'date_of_birth',
            Mail::queued(ChangeRequestStatusMail::class)->first()->mailData['field_name']
        );
    }

    #[Test]
    public function the_business_address_is_used_when_the_borrower_has_no_personal_one(): void
    {
        $borrower = $this->borrower(['email' => null, 'business_email' => 'accounts@janes-shop.test']);

        $this->notifier($this->host())->decisionMade($this->changeRequest($borrower));

        $this->assertSame(
            ['accounts@janes-shop.test'],
            $this->recipientsOf(Mail::queued(ChangeRequestStatusMail::class)->first())
        );
    }

    #[Test]
    public function a_borrower_with_no_address_at_all_is_not_emailed(): void
    {
        $borrower = $this->borrower(['email' => null, 'business_email' => null]);

        $this->notifier($this->host())->decisionMade($this->changeRequest($borrower));

        Mail::assertNothingQueued();
    }

    // ── The bell ──────────────────────────────────────────────────────

    #[Test]
    public function a_submission_rings_the_hosts_bell(): void
    {
        $this->notifier($this->host())->requestSubmitted($this->changeRequest());

        $this->assertBelled($this->host(), 'host.change_request.submitted', fn (array $data) => $data['body'] === 'Jane Smith asked to change their Date of Birth.'
            && $data['url'] === '/intake-review/change-requests');
    }

    #[Test]
    public function a_host_with_no_address_still_hears_about_it_in_the_bell(): void
    {
        $host = $this->host(['email' => null]);

        $this->notifier($host)->requestSubmitted($this->changeRequest());

        Mail::assertNothingQueued();
        $this->assertBelled($host, 'host.change_request.submitted');
    }

    #[Test]
    public function a_decision_rings_the_borrowers_bell_without_the_new_value(): void
    {
        // The new value can be a date of birth or an SSN; the bell is stored
        // and pushed over the socket, so it names the field only.
        $borrower = $this->borrower();

        $this->notifier($this->host())->decisionMade($this->changeRequest($borrower));

        $this->assertBelled($borrower, 'borrower.change_request.decided', fn (array $data) => $data['body'] === 'Your request to change your Date of Birth was approved.'
            && !str_contains(json_encode($data), '1981-06-06'));
    }

    #[Test]
    public function a_borrower_with_no_address_still_hears_about_it_in_the_bell(): void
    {
        $borrower = $this->borrower(['email' => null, 'business_email' => null]);

        $this->notifier($this->host())->decisionMade($this->changeRequest($borrower));

        Mail::assertNothingQueued();
        $this->assertBelled($borrower, 'borrower.change_request.decided');
    }

    // ── Failures stay out of the caller's way ─────────────────────────

    #[Test]
    public function a_mail_failure_never_reaches_the_caller(): void
    {
        // The request is already saved, or already applied to the borrower's
        // record. A dead mail server must not turn that into an error on the
        // borrower's or the reviewer's screen.
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('simulated transport failure'));

        $notifier = $this->notifier($this->host());

        $notifier->requestSubmitted($this->changeRequest());
        $notifier->decisionMade($this->changeRequest());

        // Reaching here at all is the assertion: neither call threw.
        $this->assertTrue(true);
    }

    #[Test]
    public function both_messages_are_queued_rather_than_sent_inline(): void
    {
        // A borrower waits on the submission response and an admin waits on the
        // decision response; an SMTP round trip takes seconds. Queuing also lets
        // MailConfig retry a transient refusal instead of swallowing it.
        $this->assertInstanceOf(
            ShouldQueue::class,
            new NewChangeRequestSubmittedMail($this->host(), $this->changeRequest())
        );
        $this->assertInstanceOf(
            ShouldQueue::class,
            new ChangeRequestStatusMail([
                'borrower_name' => 'x', 'field_name' => 'f', 'status' => 'approved', 'new_value' => 'v',
            ], self::PARTNER_ID)
        );
    }
}
