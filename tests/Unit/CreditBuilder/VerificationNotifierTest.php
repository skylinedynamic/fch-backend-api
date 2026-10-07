<?php

namespace Tests\Unit\CreditBuilder;

use App\Mail\Borrower\BorrowerVerificationStatusMail;
use App\Mail\CreditBuilder\TaskVerificationRequestedMail;
use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use App\Models\Partner\Partner;
use App\Services\CreditBuilder\VerificationNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Notifications\DynamicNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\Support\FakesBell;
use Tests\TestCase;

/**
 * Who is told about a credit builder verification, and whose mailbox it comes
 * from.
 *
 * Two rules, and they are easy to get backwards:
 *
 *   borrower submits  -> the HOST is told,     from the HOST's mailbox
 *   decision reached  -> the BORROWER is told, from THEIR PARTNER's mailbox
 *
 * Nobody else is mailed. The partner is deliberately not copied on a decision.
 *
 * Which mailbox is used is not visible in the message itself - it is the
 * `mailPartnerId` the MailConfig trait resolves credentials from - so these
 * assert that property directly. Getting it wrong sends a borrower's mail out
 * through the reviewing admin's mail server, which is exactly what the earlier
 * code did by ignoring the partner id it was handed.
 *
 * No database: models are built in memory with their relations pre-set, and the
 * one lookup the notifier needs is overridden below.
 */
#[CoversClass(VerificationNotifier::class)]
class VerificationNotifierTest extends TestCase
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

    private function borrower(?Partner $partner, array $attributes = []): Borrower
    {
        $borrower = new Borrower(array_merge([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.test',
        ], $attributes));

        $borrower->id = 7;
        $borrower->partner_id = $partner?->id;
        $borrower->setRelation('partner', $partner);
        // Pre-set so the `?: $borrower->user->email` fallback cannot reach the
        // database when the borrower has no address of their own.
        $borrower->setRelation('user', null);

        return $borrower;
    }

    /**
     * A submitted request, with its task and tier already attached.
     */
    private function request(string $taskTitle, string $tierName, int $tierOrder): BorrowerTaskVerificationRequest
    {
        $tier = new Tier(['name' => $tierName, 'order' => $tierOrder]);

        $task = new TierTask(['title' => $taskTitle]);
        $task->setRelation('tier', $tier);

        $request = new BorrowerTaskVerificationRequest();
        $request->setRelation('task', $task);

        return $request;
    }

    /**
     * The notifier with its single database lookup stubbed out.
     */
    private function notifier(?Partner $host): VerificationNotifier
    {
        return new class($host) extends VerificationNotifier {
            public function __construct(private ?Partner $host)
            {
            }

            protected function hostPartner(): ?Partner
            {
                return $this->host;
            }
        };
    }

    private function host(array $attributes = []): Partner
    {
        return $this->partner(self::HOST_ID, array_merge([
            'is_host' => true,
            'company_name' => 'Four Corner Holdings',
            'email' => 'ops@fourcorner.test',
        ], $attributes));
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

    /**
     * The payload a mailable was built with.
     *
     * Read by reflection because the two mailables differ - one exposes it, the
     * other keeps it protected - and asserting on the payload avoids calling
     * envelope(), which resolves branding from the database.
     */
    private function mailDataOf(object $mailable): array
    {
        $property = new ReflectionProperty($mailable, 'mailData');
        $property->setAccessible(true);

        return $property->getValue($mailable);
    }

    /** @return array<int, array{task_name: string, is_verified: bool, message: string, skipped?: bool}> */
    private function results(): array
    {
        return [
            ['task_name' => 'Verify business address', 'is_verified' => true, 'message' => 'Verified'],
        ];
    }

    // ── A borrower submits ────────────────────────────────────────────

    #[Test]
    public function a_submission_is_announced_to_the_host_from_the_hosts_own_mailbox(): void
    {
        $host = $this->host();
        $borrower = $this->borrower($this->partner(self::PARTNER_ID));

        $this->notifier($host)->requestSubmitted($borrower, collect([
            $this->request('Verify business address', 'Tier 1', 2),
        ]));

        $queued = Mail::queued(TaskVerificationRequestedMail::class);

        $this->assertCount(1, $queued);
        $this->assertSame(['ops@fourcorner.test'], $this->recipientsOf($queued->first()));
        $this->assertSame(
            self::HOST_ID,
            $this->sendingPartnerId($queued->first()),
            'the submission notice must be sent from the host mailbox, not the borrower partner\'s'
        );
    }

    #[Test]
    public function a_bulk_submission_is_one_email_listing_every_task_with_its_tier(): void
    {
        // Submitted highest tier first, so the ordering below is the notifier's
        // doing rather than the order it happened to receive them in.
        $this->notifier($this->host())->requestSubmitted(
            $this->borrower($this->partner(self::PARTNER_ID)),
            collect([
                $this->request('Open a trade line', 'Tier 2', 3),
                $this->request('Verify business phone', 'Tier 1', 2),
                $this->request('EIN consistency check', 'Tier 0', 1),
            ])
        );

        $queued = Mail::queued(TaskVerificationRequestedMail::class);

        // Eight tasks must not mean eight emails to the same inbox.
        $this->assertCount(1, $queued, 'a bulk submission must produce a single email');

        $tasks = $this->mailDataOf($queued->first())['tasks'];

        $this->assertSame(
            [
                ['Tier 0', 'EIN consistency check'],
                ['Tier 1', 'Verify business phone'],
                ['Tier 2', 'Open a trade line'],
            ],
            array_map(fn ($t) => [$t['tier_name'], $t['task_name']], $tasks)
        );
    }

    #[Test]
    public function the_payload_carries_the_borrower_and_every_task(): void
    {
        // envelope() is deliberately not called: building the subject resolves
        // branding, which needs the database. The payload it is built from is
        // what this can assert without one.
        foreach ([1, 3] as $count) {
            Mail::fake();

            $this->notifier($this->host())->requestSubmitted(
                $this->borrower($this->partner(self::PARTNER_ID)),
                collect(array_fill(0, $count, $this->request('A task', 'Tier 1', 2)))
            );

            $data = $this->mailDataOf(Mail::queued(TaskVerificationRequestedMail::class)->first());

            $this->assertSame('Jane Smith', $data['borrower_name']);
            $this->assertSame('jane@example.test', $data['borrower_email']);
            $this->assertSame('Northwind Capital', $data['partner_name']);
            $this->assertCount($count, $data['tasks']);
        }
    }

    #[Test]
    public function the_task_count_pluralises(): void
    {
        // The subject reads "(1 task)" / "(3 tasks)". The rule lives in the
        // translation rather than a ternary, so it is asserted there.
        $this->assertSame('1 task', trans_choice('verification_center.task_count', 1, ['count' => 1]));
        $this->assertSame('3 tasks', trans_choice('verification_center.task_count', 3, ['count' => 3]));
    }

    #[Test]
    public function only_the_hosts_account_email_is_used(): void
    {
        // contact_person_email is a different person, not a fallback inbox.
        $host = $this->host([
            'email' => 'ops@fourcorner.test',
            'contact_person_email' => 'contact@fourcorner.test',
        ]);

        $this->notifier($host)->requestSubmitted(
            $this->borrower($this->partner(self::PARTNER_ID)),
            collect([$this->request('A task', 'Tier 1', 2)])
        );

        $this->assertSame(
            ['ops@fourcorner.test'],
            $this->recipientsOf(Mail::queued(TaskVerificationRequestedMail::class)->first())
        );
    }

    #[Test]
    public function a_contact_address_alone_is_not_written_to(): void
    {
        // No account email: reported, not redirected to the named contact.
        $host = $this->host(['email' => null, 'contact_person_email' => 'contact@fourcorner.test']);

        $this->notifier($host)->requestSubmitted(
            $this->borrower($this->partner(self::PARTNER_ID)),
            collect([$this->request('A task', 'Tier 1', 2)])
        );

        Mail::assertNothingQueued();
    }

    #[Test]
    public function nothing_is_sent_when_the_host_has_no_address(): void
    {
        // Logged and dropped rather than thrown: the request is already saved.
        $host = $this->host(['email' => null, 'contact_person_email' => null]);

        $this->notifier($host)->requestSubmitted(
            $this->borrower($this->partner(self::PARTNER_ID)),
            collect([$this->request('A task', 'Tier 1', 2)])
        );

        Mail::assertNothingQueued();
    }

    #[Test]
    public function an_empty_submission_announces_nothing(): void
    {
        $this->notifier($this->host())->requestSubmitted(
            $this->borrower($this->partner(self::PARTNER_ID)),
            collect()
        );

        Mail::assertNothingQueued();
    }

    // ── A decision is reached ─────────────────────────────────────────

    #[Test]
    public function a_decision_emails_the_borrower_from_their_own_partners_mailbox(): void
    {
        $borrower = $this->borrower($this->partner(self::PARTNER_ID));

        $this->notifier($this->host())->decisionMade($borrower, BorrowerTaskVerificationRequest::COMPLETED, null, $this->results());

        $queued = Mail::queued(BorrowerVerificationStatusMail::class);

        $this->assertCount(1, $queued);
        $this->assertSame(['jane@example.test'], $this->recipientsOf($queued->first()));

        // The whole point. A decision is made while an admin is authenticated,
        // so anything relying on the ambient partner would send this from the
        // reviewer's mailbox instead of the borrower's own partner's.
        $this->assertSame(
            self::PARTNER_ID,
            $this->sendingPartnerId($queued->first()),
            "the borrower's mail must be sent from their own partner's mailbox"
        );
    }

    #[Test]
    public function a_decision_emails_nobody_but_the_borrower(): void
    {
        // The partner used to be copied; that was removed. One message, total.
        $this->notifier($this->host())->decisionMade(
            $this->borrower($this->partner(self::PARTNER_ID)),
            BorrowerTaskVerificationRequest::COMPLETED,
            null,
            $this->results()
        );

        Mail::assertQueuedCount(1);
        Mail::assertNotQueued(TaskVerificationRequestedMail::class);
    }

    #[Test]
    public function a_borrower_with_no_address_is_not_emailed(): void
    {
        $borrower = $this->borrower($this->partner(self::PARTNER_ID), ['email' => null]);

        $this->notifier($this->host())->decisionMade($borrower, BorrowerTaskVerificationRequest::COMPLETED, null, $this->results());

        Mail::assertNothingQueued();
    }

    #[Test]
    public function skipped_tasks_are_left_out_of_the_email(): void
    {
        $this->notifier($this->host())->decisionMade(
            $this->borrower($this->partner(self::PARTNER_ID)),
            BorrowerTaskVerificationRequest::COMPLETED,
            null,
            [
                ['task_name' => 'Reviewed task', 'is_verified' => true, 'message' => 'Verified'],
                ['task_name' => 'Never looked at', 'is_verified' => false, 'message' => 'No API', 'skipped' => true],
            ]
        );

        $results = $this->mailDataOf(Mail::queued(BorrowerVerificationStatusMail::class)->first())['task_results'];

        $this->assertCount(1, $results);
        $this->assertSame('Reviewed task', $results[0]['task_name']);
    }

    #[Test]
    public function a_decision_of_only_skipped_tasks_is_not_announced(): void
    {
        // Nothing was actually reviewed, so there is no outcome to report.
        $this->notifier($this->host())->decisionMade(
            $this->borrower($this->partner(self::PARTNER_ID)),
            BorrowerTaskVerificationRequest::COMPLETED,
            null,
            [['task_name' => 'x', 'is_verified' => false, 'message' => 'No API', 'skipped' => true]]
        );

        Mail::assertNothingQueued();
    }

    #[Test]
    public function the_subject_reflects_the_outcome(): void
    {
        // Not just verified-or-rejected: a request left processing or pending
        // used to be labelled "Rejected", which is a different thing to tell
        // someone.
        $cases = [
            BorrowerTaskVerificationRequest::COMPLETED => 'Verified',
            BorrowerTaskVerificationRequest::REJECTED => 'Rejected',
            BorrowerTaskVerificationRequest::PROCESSING => 'Processing',
        ];

        foreach ($cases as $status => $label) {
            Mail::fake();

            $this->notifier($this->host())->decisionMade(
                $this->borrower($this->partner(self::PARTNER_ID)),
                $status,
                null,
                $this->results()
            );

            $this->assertStringContainsString(
                $label,
                $this->mailDataOf(Mail::queued(BorrowerVerificationStatusMail::class)->first())['subject'],
                "status '{$status}' should be described as '{$label}'"
            );
        }
    }

    // ── The bell ──────────────────────────────────────────────────────

    #[Test]
    public function a_submission_rings_the_hosts_bell_with_a_link_to_that_borrowers_requests(): void
    {
        $this->notifier($this->host())->requestSubmitted(
            $this->borrower($this->partner(self::PARTNER_ID)),
            collect([$this->request('A task', 'Tier 1', 2), $this->request('B task', 'Tier 1', 2)])
        );

        $this->assertBelled($this->host(), 'host.credit_builder.verification_requested', fn (array $data) => $data['url'] === '/verification-center/borrower-requests/7'
            && $data['body'] === 'Jane Smith submitted 2 credit builder task(s) for verification.');
    }

    #[Test]
    public function a_host_with_no_address_still_hears_about_it_in_the_bell(): void
    {
        $host = $this->host(['email' => null]);

        $this->notifier($host)->requestSubmitted(
            $this->borrower($this->partner(self::PARTNER_ID)),
            collect([$this->request('A task', 'Tier 1', 2)])
        );

        Mail::assertNothingQueued();
        $this->assertBelled($host, 'host.credit_builder.verification_requested');
    }

    #[Test]
    public function a_decision_rings_the_borrowers_bell_and_nobody_elses(): void
    {
        $borrower = $this->borrower($this->partner(self::PARTNER_ID));

        $this->notifier($this->host())->decisionMade($borrower, BorrowerTaskVerificationRequest::COMPLETED, null, $this->results());

        $this->assertBelled($borrower, 'borrower.credit_builder.verification_decided', fn (array $data) => $data['title'] === 'Task verification: Verified'
            && $data['url'] === '/credit-builder');
        Notification::assertSentTimes(DynamicNotification::class, 1);
    }

    // ── Failures stay out of the caller's way ─────────────────────────

    #[Test]
    public function a_mail_failure_never_reaches_the_caller(): void
    {
        // The borrower has already submitted, or the reviewer has already
        // decided and the rows are written. A dead mail server must not turn
        // that into an error on their screen.
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('simulated transport failure'));

        $notifier = $this->notifier($this->host());
        $borrower = $this->borrower($this->partner(self::PARTNER_ID));

        $notifier->decisionMade($borrower, BorrowerTaskVerificationRequest::COMPLETED, null, $this->results());
        $notifier->requestSubmitted($borrower, collect([$this->request('A task', 'Tier 1', 2)]));

        // Reaching here at all is the assertion: neither call threw.
        $this->assertTrue(true);
    }

    #[Test]
    public function both_messages_are_queued_rather_than_sent_inline(): void
    {
        // A decision sends mail while an admin waits on the response, and an
        // SMTP round trip takes seconds. Queuing also lets MailConfig retry a
        // transient refusal instead of swallowing it.
        $this->assertInstanceOf(ShouldQueue::class, new TaskVerificationRequestedMail(['tasks' => []], self::HOST_ID));
        $this->assertInstanceOf(ShouldQueue::class, new BorrowerVerificationStatusMail([
            'subject' => 's', 'borrower_name' => 'x', 'status' => BorrowerTaskVerificationRequest::COMPLETED, 'task_results' => [],
        ], self::PARTNER_ID));
    }
}
